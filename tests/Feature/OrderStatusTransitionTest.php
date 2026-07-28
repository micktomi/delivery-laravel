<?php

namespace Tests\Feature;

use App\Actions\CancelOrder;
use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Livewire\KitchenHistory;
use App\Livewire\OrderBoard;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B6: the board polls every 10s, so two tablets routinely show the same order
 * in the same column. Each tapping "next" once used to push the order two
 * steps forward. S5: nothing could ever cancel an order.
 */
class OrderStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_advancing_moves_exactly_one_step(): void
    {
        $order = Order::factory()->status(OrderStatus::Nea)->create();

        $updated = app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);

        $this->assertSame(OrderStatus::Preparing, $updated->status);
    }

    public function test_a_stale_expectation_is_refused(): void
    {
        $order = Order::factory()->status(OrderStatus::Nea)->create();

        // Tablet 1 advances it.
        app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);

        // Tablet 2 still shows ΝΕΑ and taps once.
        try {
            app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);
            $this->fail('Expected the stale transition to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ΕΤΟΙΜΑΖΕΤΑΙ', $e->validator->errors()->first('status'));
        }

        $this->assertSame(OrderStatus::Preparing, $order->fresh()->status);
    }

    public function test_two_boards_tapping_once_each_do_not_skip_a_step(): void
    {
        $order = Order::factory()->status(OrderStatus::Preparing)->create();
        $user = User::factory()->create();

        $board1 = Livewire::actingAs($user)->test(OrderBoard::class);
        $board2 = Livewire::actingAs($user)->test(OrderBoard::class);

        $board1->call('advance', $order->id, OrderStatus::Preparing->value);
        $board2->call('advance', $order->id, OrderStatus::Preparing->value);

        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);
        $board2->assertHasErrors('board');
    }

    public function test_a_completed_order_cannot_advance(): void
    {
        $order = Order::factory()->status(OrderStatus::Completed)->create();

        $this->expectException(ValidationException::class);

        try {
            app(TransitionOrderStatus::class)->execute($order, OrderStatus::Completed);
        } finally {
            $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
        }
    }

    public function test_full_forward_path(): void
    {
        $order = Order::factory()->status(OrderStatus::Nea)->create();

        foreach ([
            [OrderStatus::Nea, OrderStatus::Preparing],
            [OrderStatus::Preparing, OrderStatus::Ready],
            [OrderStatus::Ready, OrderStatus::Out],
            [OrderStatus::Out, OrderStatus::Completed],
        ] as [$from, $to]) {
            $this->assertSame($to, app(TransitionOrderStatus::class)->execute($order, $from)->status);
        }
    }

    /** S5 */
    public function test_kitchen_can_cancel_an_open_order(): void
    {
        $order = Order::factory()->status(OrderStatus::Preparing)->create();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(OrderBoard::class)
            ->call('cancel', $order->id)
            ->assertHasNoErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    /** S5 */
    public function test_a_cancelled_order_leaves_the_board_and_the_revenue(): void
    {
        $user = User::factory()->create();
        $cancelled = Order::factory()->status(OrderStatus::Nea)->create([
            'customer_name' => 'Ακυρωμένος Πελάτης',
            'total' => 20.00,
        ]);
        Order::factory()->status(OrderStatus::Completed)->create(['total' => 10.00]);

        app(CancelOrder::class)->execute($cancelled);

        Livewire::actingAs($user)
            ->test(OrderBoard::class)
            ->assertDontSee('Ακυρωμένος Πελάτης');

        Livewire::actingAs($user)
            ->test(KitchenHistory::class)
            ->assertViewHas('dailyRevenue', 10.00)
            ->assertViewHas('cancelledCount', 1);
    }

    /** S5 */
    public function test_completed_and_cancelled_orders_cannot_be_cancelled(): void
    {
        foreach ([OrderStatus::Completed, OrderStatus::Cancelled] as $status) {
            $order = Order::factory()->status($status)->create();

            try {
                app(CancelOrder::class)->execute($order);
                $this->fail('Expected cancelling a '.$status->value.' order to be refused.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('δεν μπορεί να ακυρωθεί', $e->validator->errors()->first('status'));
            }

            $this->assertSame($status, $order->fresh()->status);
        }
    }

    /** S5 */
    public function test_the_customer_sees_that_the_order_was_cancelled(): void
    {
        $order = Order::factory()->status(OrderStatus::Nea)->create();

        app(CancelOrder::class)->execute($order);

        $this->get(route('order.track', $order))
            ->assertOk()
            ->assertSee('Η παραγγελία ακυρώθηκε');
    }
}
