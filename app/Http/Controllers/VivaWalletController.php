<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\VivaWalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class VivaWalletController extends Controller
{
    private const LATEST_PUBLIC_ORDER_SESSION_KEY = 'latest_public_order_route_key';

    public function start(Order $order, VivaWalletService $viva): RedirectResponse
    {
        abort_unless((bool) config('services.viva.enabled'), 404);
        $this->assertCustomerOwns($order);
        abort_unless($order->payment_method === PaymentMethod::Viva, 404);

        if ($order->payment_status === 'paid') {
            return redirect()->route('order.track', $order);
        }

        try {
            $orderCode = Cache::lock('viva:start:'.$order->getKey(), 20)->block(
                5,
                function () use ($order, $viva): string {
                    $order->refresh();

                    if (filled($order->viva_order_code)) {
                        return (string) $order->viva_order_code;
                    }

                    $orderCode = $viva->createPaymentOrder($order);

                    Order::query()
                        ->whereKey($order->getKey())
                        ->whereNull('viva_order_code')
                        ->update(['viva_order_code' => $orderCode]);

                    $order->refresh();

                    return (string) $order->viva_order_code;
                },
            );

            return redirect()->away($viva->checkoutUrl($orderCode));
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
        $this->assertWebhookCredentials($request, $viva);

        $payload = $request->all();
        $transactionId = strtolower((string) data_get($payload, 'EventData.TransactionId', ''));

        try {
            $result = $viva->processWebhook($payload);

            return response()->json(['status' => $result]);
        } catch (Throwable $e) {
            $viva->logPaymentEvent('error', 'viva.webhook_processing_failed', [
                'transaction_id' => Str::isUuid($transactionId) ? $transactionId : null,
                'exception' => $e::class,
            ]);

            // A non-2xx response asks Viva to retry a transient API/database failure.
            return response()->json(['status' => 'retry'], 503);
        }
    }

    /**
     * The payload itself proves nothing — the transaction is always re-read
     * from Viva before anything is marked paid — but an anonymous endpoint
     * still lets a stranger drive that outbound call. When Basic credentials
     * are configured on the webhook in the Viva dashboard, they are required
     * here; with none configured the endpoint behaves exactly as before.
     */
    private function assertWebhookCredentials(Request $request, VivaWalletService $viva): void
    {
        $username = (string) config('services.viva.webhook_username');
        $password = (string) config('services.viva.webhook_password');

        // Neither set: the endpoint stays anonymous, exactly as it was before
        // the option existed.
        if ($username === '' && $password === '') {
            return;
        }

        // One of the two set is a half-finished deployment rather than a
        // decision. Refuse instead of guessing which way it was meant, and
        // refuse here, before Viva is asked anything.
        if ($username === '' || $password === '') {
            $viva->logPaymentEvent('error', 'viva.webhook_credentials_misconfigured', [
                'missing' => $username === '' ? 'username' : 'password',
            ]);

            abort(500);
        }

        // Both comparisons run before the verdict, so a wrong username cannot
        // be told apart from a wrong password by how long the reply took.
        $matchesUsername = hash_equals($username, (string) $request->getUser());
        $matchesPassword = hash_equals($password, (string) $request->getPassword());

        abort_unless($matchesUsername && $matchesPassword, 401);
    }

    private function returnOrder(Request $request): Order
    {
        $orderCode = (string) $request->query('s', '');

        abort_unless(preg_match('/^\d{1,32}$/D', $orderCode), 404);

        return Order::query()->where('viva_order_code', $orderCode)->firstOrFail();
    }

    private function assertCustomerOwns(Order $order): void
    {
        $sessionToken = (string) session(self::LATEST_PUBLIC_ORDER_SESSION_KEY, '');
        $routeToken = (string) $order->getRouteKey();

        abort_unless($sessionToken !== '' && hash_equals($routeToken, $sessionToken), 404);
    }
}
