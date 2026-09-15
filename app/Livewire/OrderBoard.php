<?php

namespace App\Livewire;

use App\Actions\CancelOrder;
use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class OrderBoard extends Component
{
    private const BOARD_STATUSES = [OrderStatus::Nea, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Out];

    public int $lastSeenOrderId = 0;

    /**
     * The orders this board showed at its last render. An order missing here
     * is newly eligible even when its id is below $lastSeenOrderId, e.g. an
     * older Viva order whose payment was confirmed after a newer cash order.
     *
     * @var list<int>
     */
    #[Locked]
    public array $boardOrderIds = [];

    public function mount(): void
    {
        $this->boardOrderIds = $this->eligibleOrderIds();
        $this->lastSeenOrderId = max([0, ...$this->boardOrderIds]);
    }

    /**
     * $expectedStatus is what this board was showing when the button was drawn;
     * if the order moved on meanwhile the tap is refused instead of skipping a step.
     */
    public function advance(int $orderId, string $expectedStatus): void
    {
        $order = Order::find($orderId);
        $expected = OrderStatus::tryFrom($expectedStatus);

        if (! $order || ! $expected || ! $order->isReadyForFulfilment()) {
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
        $columns = [];
        foreach (self::BOARD_STATUSES as $status) {
            $columns[$status->value] = [
                'status' => $status,
                'orders' => Order::where('status', $status->value)
                    ->readyForFulfilment()
                    ->with('items')
                    ->orderBy('placed_at')
                    ->get(),
            ];
        }

        $orderIds = $this->eligibleOrderIds();
        $currentMaxId = max([0, ...$orderIds]);
        $newlyEligible = array_diff($orderIds, $this->boardOrderIds);
        $this->boardOrderIds = $orderIds;

        // A new highest id is still re-announced until the board acknowledges
        // it; an order that only now became eligible below that id is
        // announced on this render alone, so the next poll stays quiet.
        $hasNewOrders = $currentMaxId > $this->lastSeenOrderId || $newlyEligible !== [];

        if ($hasNewOrders) {
            $this->dispatch('new-orders', maxId: $currentMaxId);
        }

        return view('livewire.order-board', [
            'columns' => $columns,
            'currentMaxId' => $currentMaxId,
        ])->layout('layouts.kitchen');
    }

    /**
     * Orders the kitchen can act on: on the board and, for Viva, paid.
     *
     * @return list<int>
     */
    private function eligibleOrderIds(): array
    {
        return Order::whereIn('status', array_map(fn (OrderStatus $status) => $status->value, self::BOARD_STATUSES))
            ->readyForFulfilment()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
