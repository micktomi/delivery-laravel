<?php

namespace App\Livewire;

use App\Actions\CancelOrder;
use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

class OrderBoard extends Component
{
    public int $lastSeenOrderId = 0;

    public function mount(): void
    {
        $this->lastSeenOrderId = (int) Order::max('id');
    }

    /**
     * $expectedStatus is what this board was showing when the button was drawn;
     * if the order moved on meanwhile the tap is refused instead of skipping a step.
     */
    public function advance(int $orderId, string $expectedStatus): void
    {
        $order = Order::find($orderId);
        $expected = OrderStatus::tryFrom($expectedStatus);

        if (! $order || ! $expected) {
            $this->addError('board', 'Η παραγγελία δεν βρέθηκε. Ο πίνακας ανανεώθηκε.');

            return;
        }

        try {
            app(TransitionOrderStatus::class)->execute($order, $expected);
        } catch (ValidationException $e) {
            $this->addError('board', $e->validator->errors()->first());
        }
    }

    public function cancel(int $orderId): void
    {
        $order = Order::find($orderId);

        if (! $order) {
            $this->addError('board', 'Η παραγγελία δεν βρέθηκε. Ο πίνακας ανανεώθηκε.');

            return;
        }

        try {
            app(CancelOrder::class)->execute($order);
        } catch (ValidationException $e) {
            $this->addError('board', $e->validator->errors()->first());
        }
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
