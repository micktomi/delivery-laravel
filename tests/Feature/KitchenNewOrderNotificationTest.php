<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Livewire\OrderBoard;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KitchenNewOrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_already_on_the_board_when_it_opens_are_not_announced(): void
    {
        Order::factory()->create();

        $this->assertSame(0, $this->newOrderEvents($this->poll($this->board())));
    }

    public function test_a_new_cash_order_is_announced_until_the_board_acknowledges_it(): void
    {
        $board = $this->board();
        $cash = Order::factory()->create();

        $this->poll($board)->assertDispatched('new-orders', maxId: $cash->id);
        $this->poll($board)->assertDispatched('new-orders', maxId: $cash->id);

        $board->call('acknowledge', $cash->id);

        $this->poll($board)->assertNotDispatched('new-orders');
    }

    public function test_an_older_viva_order_paid_after_a_newer_cash_order_was_seen_is_announced_exactly_once(): void
    {
        $board = $this->board();

        $viva = Order::factory()->create([
            'payment_method' => PaymentMethod::Viva->value,
            'payment_status' => 'pending',
        ]);

        $this->poll($board)->assertNotDispatched('new-orders');

        $cash = Order::factory()->create();
        $this->assertLessThan($cash->id, $viva->id);

        $this->poll($board)->assertDispatched('new-orders', maxId: $cash->id);
        $board->call('acknowledge', $cash->id);
        $this->poll($board)->assertNotDispatched('new-orders');

        $viva->forceFill(['payment_status' => 'paid'])->save();

        $this->assertSame(1, $this->newOrderEvents($this->poll($board)));
        $this->assertSame(0, $this->newOrderEvents($this->poll($board)));
        $this->assertSame(0, $this->newOrderEvents($this->poll($board)));
    }

    public static function terminalStatuses(): array
    {
        return [
            'cancelled' => [OrderStatus::Cancelled],
            'completed' => [OrderStatus::Completed],
        ];
    }

    #[DataProvider('terminalStatuses')]
    public function test_a_terminal_viva_order_that_is_paid_later_is_not_announced(OrderStatus $status): void
    {
        $board = $this->board();
        $cash = Order::factory()->create();
        $this->poll($board);
        $board->call('acknowledge', $cash->id);

        // Newer than everything seen, so neither the highest-id check nor the
        // newly-eligible check may pick it up once its payment lands.
        $viva = Order::factory()->status($status)->create([
            'payment_method' => PaymentMethod::Viva->value,
            'payment_status' => 'pending',
        ]);
        $this->poll($board)->assertNotDispatched('new-orders');

        $viva->forceFill(['payment_status' => 'paid'])->save();

        $this->poll($board)->assertNotDispatched('new-orders');
    }

    private function board(): Testable
    {
        return Livewire::actingAs(User::factory()->create())->test(OrderBoard::class);
    }

    private function poll(Testable $board): Testable
    {
        return $board->call('$refresh');
    }

    private function newOrderEvents(Testable $board): int
    {
        return collect($board->effects['dispatches'] ?? [])->where('name', 'new-orders')->count();
    }
}
