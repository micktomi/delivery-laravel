<?php

namespace App\Livewire;

use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use Livewire\Attributes\On;
use Livewire\Component;

class OrderBoard extends Component
{
    public int $lastSeenOrderId = 0;

    public function mount(): void
    {
        $this->lastSeenOrderId = (int) Order::max('id');
    }

    public function advance(int $orderId): void
    {
        $order = Order::findOrFail($orderId);
        app(TransitionOrderStatus::class)->execute($order);
    }

    public function acknowledge(int $newMaxId): void
    {
        $this->lastSeenOrderId = $newMaxId;
    }

    public function render()
    {
        $statuses = [OrderStatus::Nea, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Out];

        $columns = [];
        foreach ($statuses as $status) {
            $columns[$status->value] = [
                'status' => $status,
                'orders' => Order::where('status', $status->value)
                    ->whereDate('created_at', today())
                    ->with('items')
                    ->orderBy('placed_at')
                    ->get(),
            ];
        }

        $currentMaxId = (int) Order::max('id');
        $hasNewOrders = $currentMaxId > $this->lastSeenOrderId;

        if ($hasNewOrders) {
            $this->dispatch('new-orders', maxId: $currentMaxId);
        }

        return view('livewire.order-board', [
            'columns' => $columns,
            'currentMaxId' => $currentMaxId,
        ])->layout('layouts.kitchen');
    }
}
