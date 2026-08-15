<?php

namespace App\Actions;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\Order;
use App\Models\OrderDriverTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransitionDriverDelivery
{
    /**
     * Claim is a single conditional UPDATE. The database, not a stale browser
     * render, decides which driver receives the ready order.
     */
    public function claim(int $orderId, Driver $driver): Order
    {
        return DB::transaction(function () use ($orderId, $driver) {
            // Serializes claims made by the same driver as well, so a double
            // tap cannot give one driver two simultaneous active deliveries.
            Driver::query()->whereKey($driver->getKey())->lockForUpdate()->firstOrFail();

            if ($this->hasActiveDelivery($driver)) {
                throw ValidationException::withMessages([
                    'delivery' => 'Ολοκληρώστε πρώτα την ενεργή διανομή σας.',
                ]);
            }

            $claimed = Order::query()
                ->readyForFulfilment()
                ->whereKey($orderId)
                ->where('status', OrderStatus::Ready->value)
                ->whereNull('driver_id')
                ->whereNull('delivery_status')
                ->update([
                    'driver_id' => $driver->getKey(),
                    'delivery_status' => DeliveryStatus::Assigned->value,
                    'updated_at' => now(),
                ]);

            if ($claimed !== 1) {
                throw ValidationException::withMessages([
                    'delivery' => 'Η παραγγελία δεν είναι πλέον διαθέσιμη για ανάληψη.',
                ]);
            }

            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $this->audit($order, $driver, OrderStatus::Ready->value, DeliveryStatus::Assigned->value);

            return $order->fresh();
        });
    }

    public function pickUp(int $orderId, Driver $driver): Order
    {
        return $this->transition(
            $orderId,
            $driver,
            DeliveryStatus::Assigned,
            DeliveryStatus::PickedUp,
        );
    }

    public function outForDelivery(int $orderId, Driver $driver): Order
    {
        return $this->transition(
            $orderId,
            $driver,
            DeliveryStatus::PickedUp,
            DeliveryStatus::OutForDelivery,
            OrderStatus::Out,
        );
    }

    public function deliver(int $orderId, Driver $driver): Order
    {
        return $this->transition(
            $orderId,
            $driver,
            DeliveryStatus::OutForDelivery,
            DeliveryStatus::Delivered,
            OrderStatus::Completed,
        );
    }

    private function transition(
        int $orderId,
        Driver $driver,
        DeliveryStatus $expected,
        DeliveryStatus $next,
        ?OrderStatus $nextOrderStatus = null,
    ): Order {
        return DB::transaction(function () use ($orderId, $driver, $expected, $next, $nextOrderStatus) {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if (! $order) {
                throw ValidationException::withMessages([
                    'delivery' => 'Η παραγγελία δεν βρέθηκε.',
                ]);
            }

            if ((int) $order->driver_id !== (int) $driver->getKey()) {
                throw ValidationException::withMessages([
                    'delivery' => 'Η παραγγελία δεν είναι ανατεθειμένη σε εσάς.',
                ]);
            }

            if ($order->delivery_status !== $expected) {
                throw ValidationException::withMessages([
                    'delivery' => 'Η παραγγελία έχει ήδη ενημερωθεί. Ανανέωση της λίστας.',
                ]);
            }

            if ($order->status === OrderStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'delivery' => 'Η παραγγελία έχει ακυρωθεί.',
                ]);
            }

            $values = ['delivery_status' => $next->value];
            if ($nextOrderStatus) {
                $values['status'] = $nextOrderStatus->value;
            }

            $from = $expected->value;
            $order->forceFill($values)->save();
            $this->audit($order, $driver, $from, $next->value);

            return $order->fresh();
        });
    }

    private function hasActiveDelivery(Driver $driver): bool
    {
        return Order::query()
            ->where('driver_id', $driver->getKey())
            ->whereIn('delivery_status', [
                DeliveryStatus::Assigned->value,
                DeliveryStatus::PickedUp->value,
                DeliveryStatus::OutForDelivery->value,
            ])
            ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
            ->exists();
    }

    private function audit(Order $order, Driver $driver, string $from, string $to): void
    {
        OrderDriverTransition::query()->create([
            'order_id' => $order->getKey(),
            'driver_id' => $driver->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'transitioned_at' => now(),
        ]);
    }
}
