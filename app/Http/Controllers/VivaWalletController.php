<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\VivaTransportException;
use App\Models\Order;
use App\Services\VivaWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class VivaWalletController extends Controller
{
    private const LATEST_PUBLIC_ORDER_SESSION_KEY = 'latest_public_order_route_key';

    private const PAYMENT_START_LOCK_SECONDS = 45;

    public function start(Order $order, VivaWalletService $viva): RedirectResponse
    {
        abort_unless((bool) config('services.viva.enabled'), 404);
        $this->assertCustomerOwns($order);
        abort_unless($order->payment_method === PaymentMethod::Viva, 404);

        abort_if(in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Completed], true), 409);

        if ($order->payment_status === 'paid') {
            return redirect()->route('order.track', $order);
        }

        if ($order->payment_status === VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN) {
            return $this->unknownPaymentOrderRedirect($order);
        }

        try {
            // The per-order lock serializes checkout creation/reuse. The Viva
            // request itself stays outside any database transaction: on SQLite
            // a DEFERRED transaction that has read the order holds a WAL
            // snapshot that can no longer be upgraded to a write once any
            // other request (a kitchen board poll saving its session) has
            // committed, and on MySQL a row lock would be held for the whole
            // HTTP round trip.
            return Cache::lock('viva:start:'.$order->getKey(), self::PAYMENT_START_LOCK_SECONDS)->block(
                5,
                function () use ($order, $viva): RedirectResponse {
                    $fresh = Order::query()->whereKey($order->getKey())->firstOrFail();
                    abort_if(in_array($fresh->status, [OrderStatus::Cancelled, OrderStatus::Completed], true), 409);

                    if ($fresh->payment_status === 'paid') {
                        return redirect()->route('order.track', $fresh);
                    }

                    if ($fresh->payment_status === VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN) {
                        return $this->unknownPaymentOrderRedirect($fresh);
                    }

                    if (filled($fresh->viva_order_code)) {
                        return redirect()->away($viva->checkoutUrl((string) $fresh->viva_order_code));
                    }

                    try {
                        $orderCode = $viva->createPaymentOrder($fresh);
                    } catch (VivaTransportException $e) {
                        // A token request that never connected happened before
                        // the create-order request, so it retains the normal
                        // retryable failure behaviour. Once the create request
                        // itself has been attempted, however, a missing response
                        // cannot tell us whether Viva created the payment order.
                        if ($e->endpoint !== '/checkout/v2/orders') {
                            throw $e;
                        }

                        $markedUnknown = Order::query()
                            ->whereKey($fresh->getKey())
                            ->where('payment_status', 'pending')
                            ->whereNull('viva_order_code')
                            // A cancellation may have committed while Viva's
                            // response was being lost. Preserve the unknown
                            // outcome on that cancelled order so reconciliation
                            // can still detect money that moved too late.
                            ->where('status', '!=', OrderStatus::Completed->value)
                            ->update(['payment_status' => VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN]);

                        if ($markedUnknown === 1) {
                            $fresh->payment_status = VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN;
                            $viva->logPaymentEvent('warning', 'viva.payment_order_outcome_unknown', [
                                'order_id' => $fresh->getKey(),
                            ]);

                            return $this->unknownPaymentOrderRedirect($fresh);
                        }

                        // Another state transition may have won while the HTTP
                        // request was in flight. Honour that durable truth and
                        // never overwrite it with the ambiguous state.
                        $current = Order::query()->whereKey($fresh->getKey())->firstOrFail();

                        if ($current->payment_status === VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN) {
                            return $this->unknownPaymentOrderRedirect($current);
                        }

                        if ($current->payment_status === 'paid') {
                            return redirect()->route('order.track', $current);
                        }

                        if (filled($current->viva_order_code)) {
                            return redirect()->away($viva->checkoutUrl((string) $current->viva_order_code));
                        }

                        abort(409);
                    }

                    // A single conditional UPDATE is atomic on its own: the code
                    // is stored only while the order is still open and has no
                    // code, so a cancellation that landed during the Viva call
                    // wins and the customer is never sent to pay for it.
                    $stored = Order::query()
                        ->whereKey($fresh->getKey())
                        ->whereNull('viva_order_code')
                        ->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Completed->value])
                        ->update(['viva_order_code' => $orderCode]);

                    if ($stored === 1) {
                        return redirect()->away($viva->checkoutUrl($orderCode));
                    }

                    $current = Order::query()->whereKey($fresh->getKey())->firstOrFail();

                    if (filled($current->viva_order_code)) {
                        return redirect()->away($viva->checkoutUrl((string) $current->viva_order_code));
                    }

                    $viva->logPaymentEvent('warning', 'viva.payment_order_not_stored', [
                        'order_id' => $current->getKey(),
                        'status' => $current->status->value,
                    ]);

                    abort(409);
                },
            );
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            $viva->logPaymentEvent('error', 'viva.payment_start_failed', [
                'order_id' => $order->getKey(),
                'exception' => $e::class,
            ]);

            return redirect()->route('order.track', $order)->with(
                'viva_error',
                'Η online πληρωμή δεν μπόρεσε να ξεκινήσει. Η παραγγελία σας έχει αποθηκευτεί και δεν έχει χρεωθεί.',
            );
        }
    }

    public function success(Request $request): RedirectResponse
    {
        $order = $this->returnOrder($request);
        $this->assertCustomerOwns($order);

        return redirect()->route('order.track', $order)->with(
            'viva_status',
            'Επιστρέψατε από τη Viva. Η πληρωμή θα φανεί ως ολοκληρωμένη μόνο μετά την επιβεβαίωση της Viva.',
        );
    }

    public function failure(Request $request): RedirectResponse
    {
        $order = $this->returnOrder($request);
        $this->assertCustomerOwns($order);

        return redirect()->route('order.track', $order)->with(
            'viva_error',
            'Η online πληρωμή δεν ολοκληρώθηκε. Η παραγγελία σας παραμένει απλήρωτη.',
        );
    }

    /**
     * Viva's dashboard hits this URL with GET once, when a webhook is first
     * registered, to confirm we control it. It expects the account's static
     * verification key back as JSON — nothing to do with payment payloads.
     */
    public function webhookVerify(): JsonResponse
    {
        return response()->json(['Key' => (string) config('services.viva.webhook_verification_key')]);
    }

    public function webhook(Request $request, VivaWalletService $viva): JsonResponse
    {
        $payload = $request->all();

        try {
            $result = $viva->processWebhook($payload);

            return response()->json(['status' => $result]);
        } catch (Throwable $e) {
            $viva->logPaymentEvent('error', 'viva.webhook_processing_failed', [
                'exception' => $e::class,
            ]);

            // A non-2xx response asks Viva to retry a transient API/database failure.
            return response()->json(['status' => 'retry'], 503);
        }
    }

    private function returnOrder(Request $request): Order
    {
        $orderCode = (string) $request->query('s', '');

        abort_unless(preg_match('/^\d{16}$/D', $orderCode), 404);

        return Order::query()->where('viva_order_code', $orderCode)->firstOrFail();
    }

    private function assertCustomerOwns(Order $order): void
    {
        $sessionToken = (string) session(self::LATEST_PUBLIC_ORDER_SESSION_KEY, '');
        $routeToken = (string) $order->getRouteKey();

        abort_unless($sessionToken !== '' && hash_equals($routeToken, $sessionToken), 404);
    }

    private function unknownPaymentOrderRedirect(Order $order): RedirectResponse
    {
        return redirect()->route('order.track', $order)->with(
            'viva_status',
            'Η Viva δεν επιβεβαίωσε αν δημιουργήθηκε η online πληρωμή. Μην προσπαθήσετε ξανά — η παραγγελία χρειάζεται έλεγχο.',
        );
    }
}
