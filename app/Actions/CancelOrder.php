<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\VivaPaymentOrderCancellationException;
use App\Models\Order;
use App\Models\PrintJob;
use App\Services\VivaWalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CancelOrder
{
    /**
     * Cancel an order that has not been finished yet. Cancelling is terminal:
     * a cancelled order never re-enters the board and never counts as revenue.
     */
    public function execute(Order $order): Order
    {
        // An unpaid online order is first made unpayable at Viva. This is an
        // HTTP round trip, so it happens before — never inside — the row lock
        // below; if it does not succeed the local order is left untouched.
        $remoteCancellation = $this->cancelRemotePaymentOrder($order);

        return DB::transaction(function () use ($order, $remoteCancellation) {
            $fresh = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $fresh) {
                throw ValidationException::withMessages([
                    'status' => 'Η παραγγελία δεν βρέθηκε.',
                ]);
            }

            if (in_array($fresh->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Η παραγγελία είναι ήδη σε κατάσταση '.$fresh->status->getLabel()
                        .' και δεν μπορεί να ακυρωθεί.',
                ]);
            }

            // A paid online order is never recorded as cancelled here, whether
            // the payment was already confirmed before this click or landed
            // while Viva was being asked to cancel. The money has moved: that
            // is a refund decision, and the admin inconsistency count is what
            // surfaces it. Checked on the locked row, so it cannot be skipped
            // by whatever the Viva call did or did not do.
            if ($fresh->payment_method === PaymentMethod::Viva && $fresh->payment_status === 'paid') {
                Log::warning('order.cancel_refused_payment_confirmed', [
                    'order_id' => $fresh->id,
                    'display_number' => $fresh->display_number,
                    'viva_cancellation' => $remoteCancellation,
                    'user_id' => Auth::id(),
                ]);

                throw ValidationException::withMessages([
                    'status' => $remoteCancellation !== null
                        ? 'Η online πληρωμή επιβεβαιώθηκε εν τω μεταξύ. Η παραγγελία δεν ακυρώθηκε — ελέγξτε την ξανά.'
                        : 'Η παραγγελία έχει ήδη πληρωθεί online και δεν ακυρώνεται από εδώ. Χρειάζεται επιστροφή χρημάτων από το Viva.',
                ]);
            }

            $previous = $fresh->status;
            $fresh->update(['status' => OrderStatus::Cancelled->value]);

            // Once leased, a ticket may already exist physically. Preserve its
            // attempt and acknowledgement state instead of declaring it unprinted.
            PrintJob::where('order_id', $fresh->getKey())
                ->where('status', 'pending')
                ->where('attempts', 0)
                ->whereNull('last_attempt_at')
                ->update(['status' => 'cancelled', 'last_error' => 'order_cancelled']);

            Log::warning('order.cancelled', [
                'order_id' => $fresh->id,
                'display_number' => $fresh->display_number,
                'from' => $previous->value,
                'viva_cancellation' => $remoteCancellation,
                'user_id' => Auth::id(),
            ]);

            return $fresh->fresh();
        });
    }

    /**
     * Only a pending Viva order with a stored payment order code can still be
     * paid, so only that case talks to Viva. Cash, POS-on-delivery and Viva
     * orders that never reached Smart Checkout keep the plain local
     * cancellation. An already-paid Viva order is not one of those: it
     * returns null here because there is nothing left to cancel at Viva, and
     * the paid guard inside the locked transaction is what refuses it.
     *
     * @return string|null the Viva outcome, or null when Viva was not involved
     */
    private function cancelRemotePaymentOrder(Order $order): ?string
    {
        // The caller may hold a stale model; decide on the current row.
        $current = Order::query()->whereKey($order->getKey())->first();

        if ($current?->payment_method === PaymentMethod::Viva
            && $current->payment_status === VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN) {
            throw ValidationException::withMessages([
                'status' => 'Η δημιουργία της online πληρωμής δεν έχει επιβεβαιωθεί. Ελέγξτε πρώτα τη Viva και μετά λύστε την εκκρεμή κατάσταση.',
            ]);
        }

        if (! $current
            || $current->payment_method !== PaymentMethod::Viva
            || $current->payment_status !== 'pending'
            || blank($current->viva_order_code)
            || in_array($current->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
            return null;
        }

        try {
            return app(VivaWalletService::class)->cancelPaymentOrder($current);
        } catch (VivaPaymentOrderCancellationException $e) {
            throw ValidationException::withMessages([
                'status' => $this->remoteCancellationMessage($e->reason),
            ]);
        }
    }

    private function remoteCancellationMessage(string $reason): string
    {
        return match ($reason) {
            'paid' => 'Η Viva αναφέρει ότι η παραγγελία έχει ήδη πληρωθεί. Η παραγγελία δεν ακυρώθηκε — περιμένετε την επιβεβαίωση της πληρωμής.',
            'unconfigured' => 'Δεν έχουν ρυθμιστεί τα στοιχεία Merchant API της Viva. Η online παραγγελία δεν ακυρώθηκε.',
            'unauthorized' => 'Η Viva απέρριψε τα στοιχεία Merchant API. Η online παραγγελία δεν ακυρώθηκε.',
            'unreachable' => 'Η Viva δεν απάντησε εγκαίρως. Η παραγγελία δεν ακυρώθηκε — δοκιμάστε ξανά σε λίγο.',
            'not_found' => 'Η Viva δεν βρίσκει αυτή την online παραγγελία. Η παραγγελία δεν ακυρώθηκε — ελέγξτε την στο Viva dashboard.',
            default => 'Η ακύρωση της online παραγγελίας στη Viva δεν επιβεβαιώθηκε. Η παραγγελία δεν ακυρώθηκε — δοκιμάστε ξανά ή ελέγξτε την στο Viva dashboard.',
        };
    }
}
