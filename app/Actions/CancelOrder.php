<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
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
        return DB::transaction(function () use ($order) {
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

            $previous = $fresh->status;
            $fresh->update(['status' => OrderStatus::Cancelled->value]);

            Log::warning('order.cancelled', [
                'order_id' => $fresh->id,
                'display_number' => $fresh->display_number,
                'from' => $previous->value,
                'user_id' => Auth::id(),
            ]);

            return $fresh->fresh();
        });
    }
}
