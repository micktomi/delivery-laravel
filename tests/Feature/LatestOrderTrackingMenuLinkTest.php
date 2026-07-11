<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LatestOrderTrackingMenuLinkTest extends TestCase
{
    use RefreshDatabase;

    private const LATEST_PUBLIC_ORDER_SESSION_KEY = 'latest_public_order_route_key';

    public function test_menu_shows_the_tracking_button_with_the_latest_active_order_link(): void
    {
        $order = Order::factory()->status(OrderStatus::Preparing)->create();

        $this->withSession([self::LATEST_PUBLIC_ORDER_SESSION_KEY => $order->getRouteKey()])
            ->get(route('menu'))
            ->assertOk()
            ->assertSee('Παρακολούθηση παραγγελίας')
            ->assertSee(route('order.track', $order));
    }

    public function test_menu_hides_the_tracking_button_without_a_session_order(): void
    {
        $this->get(route('menu'))
            ->assertOk()
            ->assertDontSee('Παρακολούθηση παραγγελίας');
    }

    public function test_menu_hides_the_tracking_button_and_clears_completed_or_cancelled_orders(): void
    {
        foreach ([OrderStatus::Completed, OrderStatus::Cancelled] as $status) {
            $order = Order::factory()->status($status)->create();

            $this->withSession([self::LATEST_PUBLIC_ORDER_SESSION_KEY => $order->getRouteKey()])
                ->get(route('menu'))
                ->assertOk()
                ->assertDontSee('Παρακολούθηση παραγγελίας')
                ->assertSessionMissing(self::LATEST_PUBLIC_ORDER_SESSION_KEY);
        }
    }

    public function test_menu_hides_the_tracking_button_and_clears_a_missing_order(): void
    {
        $this->withSession([self::LATEST_PUBLIC_ORDER_SESSION_KEY => 999999])
            ->get(route('menu'))
            ->assertOk()
            ->assertDontSee('Παρακολούθηση παραγγελίας')
            ->assertSessionMissing(self::LATEST_PUBLIC_ORDER_SESSION_KEY);
    }
}
