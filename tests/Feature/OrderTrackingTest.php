<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracking_page_is_publicly_accessible(): void
    {
        $order = Order::factory()->create();

        $this->get(route('order.track', $order))
            ->assertOk();
    }

    public function test_tracking_page_returns_404_for_missing_order(): void
    {
        $this->get('/order/999999/track')
            ->assertNotFound();
    }

    public function test_tracking_page_shows_order_number(): void
    {
        $order = Order::factory()->create(['display_number' => 7]);

        $this->get(route('order.track', $order))
            ->assertSee('007');
    }

    public function test_tracking_page_shows_current_status_label(): void
    {
        $order = Order::factory()->status(OrderStatus::Preparing)->create();

        $this->get(route('order.track', $order))
            ->assertSee(OrderStatus::Preparing->getLabel());
    }

    public function test_tracking_page_requires_no_authentication(): void
    {
        $order = Order::factory()->create();

        $this->get(route('order.track', $order))
            ->assertOk()
            ->assertDontSee('login');
    }
}
