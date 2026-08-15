<?php

namespace App\Livewire;

use App\Actions\TransitionDriverDelivery;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class DriverDashboard extends Component
{
    public function claim(int $orderId): void
    {
        $this->run(fn (Driver $driver) => app(TransitionDriverDelivery::class)->claim($orderId, $driver));
    }

    public function pickUp(int $orderId): void
    {
        $this->run(fn (Driver $driver) => app(TransitionDriverDelivery::class)->pickUp($orderId, $driver));
    }

    public function outForDelivery(int $orderId): void
    {
        $this->run(fn (Driver $driver) => app(TransitionDriverDelivery::class)->outForDelivery($orderId, $driver));
    }

    public function deliver(int $orderId): void
    {
        $this->run(fn (Driver $driver) => app(TransitionDriverDelivery::class)->deliver($orderId, $driver));
    }

    public function endShift(): void
    {
        $driver = $this->driver();

        $hasActiveDelivery = Order::query()
            ->where('driver_id', $driver->getKey())
            ->whereIn('delivery_status', [
                DeliveryStatus::Assigned->value,
                DeliveryStatus::PickedUp->value,
                DeliveryStatus::OutForDelivery->value,
            ])
            ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
            ->exists();

        if ($hasActiveDelivery) {
            $this->addError('driver', 'Ολοκληρώστε πρώτα την ενεργή διανομή πριν κλείσετε βάρδια.');

            return;
        }

        $shiftId = session('driver_shift_id');
        if (is_numeric($shiftId)) {
            DriverShift::query()
                ->whereKey((int) $shiftId)
                ->where('driver_id', $driver->getKey())
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);
        }

        Auth::guard('driver')->logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirectRoute('driver.login');
    }

    public function render()
    {
        $driver = $this->driver();
        $activeStatuses = [
            DeliveryStatus::Assigned->value,
            DeliveryStatus::PickedUp->value,
            DeliveryStatus::OutForDelivery->value,
        ];

        $activeOrder = Order::query()
            ->where('driver_id', $driver->getKey())
            ->whereIn('delivery_status', $activeStatuses)
            ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
            ->with('items')
            ->orderBy('updated_at')
            ->first();

        $availableOrders = $activeOrder
            ? collect()
            : Order::query()
                ->readyForFulfilment()
                ->where('status', OrderStatus::Ready->value)
                ->whereNull('driver_id')
                ->whereNull('delivery_status')
                ->with('items')
                ->orderBy('placed_at')
                ->get();

        return view('livewire.driver-dashboard', compact('driver', 'activeOrder', 'availableOrders'))
            ->layout('layouts.app');
    }

    private function run(callable $transition): void
    {
        try {
            $transition($this->driver());
        } catch (ValidationException $e) {
            $this->addError('driver', $e->validator->errors()->first('delivery'));
        }
    }

    private function driver(): Driver
    {
        $driver = Auth::guard('driver')->user();

        abort_unless($driver instanceof Driver && $driver->is_active, 403);

        return $driver;
    }
}
