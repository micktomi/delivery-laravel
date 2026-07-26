<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TransitionOrderStatus
{
    /**
     * Advance one step. The caller passes the status it believed the order was
     * in, so a tap on a stale kitchen board cannot skip a step.
     */
    public function execute(Order $order, OrderStatus $expected): Order
    {
        return DB::transaction(function () use ($order, $expected) {
            $fresh = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $fresh) {
                throw ValidationException::withMessages([
                    'status' => 'Η παραγγελία δεν βρέθηκε.',
                ]);
            }

            if ($fresh->status !== $expected) {
                throw ValidationException::withMessages([
                    'status' => 'Η παραγγελία #'.str_pad((string) $fresh->display_number, 3, '0', STR_PAD_LEFT)
                        .' είναι ήδη σε κατάσταση '.$fresh->status->getLabel().'. Ο πίνακας ανανεώθηκε.',
                ]);
            }

            $next = $fresh->status->nextStatus();

            if ($next === null) {
                throw ValidationException::withMessages([
                    'status' => 'Η παραγγελία είναι ήδη ολοκληρωμένη.',
                ]);
            }

            $fresh->update(['status' => $next->value]);

            Log::info('order.status_changed', [
                'order_id' => $fresh->id,
                'display_number' => $fresh->display_number,
                'from' => $expected->value,
                'to' => $next->value,
                'user_id' => Auth::id(),
            ]);

            return $fresh->fresh();
        });
    }
}
