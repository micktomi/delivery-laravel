<?php

namespace App\Livewire;

use App\Enums\OrderStatus;
use App\Models\Order;
use Livewire\Component;

class OrderTrackingPage extends Component
{
    public Order $order;

    public function mount(Order $order): void
    {
        $this->order = $order;
    }

    public function render()
    {
        $steps = OrderStatus::orderedStatuses();
        $currentIndex = array_search($this->order->status, $steps, true);

        return view('livewire.order-tracking-page', [
            'steps' => $steps,
            'currentIndex' => $currentIndex,
        ])->layout('layouts.app');
    }
}
