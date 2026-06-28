<?php

namespace App\Actions;

use App\Models\Order;
use Illuminate\Validation\ValidationException;

class TransitionOrderStatus
{
    public function execute(Order $order): Order
    {
        $next = $order->status->nextStatus();

        if ($next === null) {
            throw ValidationException::withMessages([
                'status' => 'Η παραγγελία είναι ήδη ολοκληρωμένη.',
            ]);
        }

        $order->update(['status' => $next->value]);

        return $order->fresh();
    }
}
