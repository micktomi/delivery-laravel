<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderResourceViewRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_the_view_action_url_uses_the_admin_id_and_loads_an_existing_order(): void
    {
        $order = Order::factory()->create();
        $order->items()->create([
            'product_name' => 'Freddo Espresso',
            'base_price' => '2.20',
            'quantity' => 1,
            'selected_options' => [[
                'group' => 'Μέγεθος / Δόση',
                'value' => 'Διπλός',
                'price_delta' => 0.70,
            ]],
            'line_total' => '2.90',
        ]);

        $url = OrderResource::getUrl('view', ['record' => $order], panel: 'admin');

        $this->assertSame(
            route('filament.admin.resources.orders.view', ['record' => $order->getKey()]),
            $url,
        );

        $this->get($url)
            ->assertOk()
            ->assertSee($order->customer_name)
            ->assertSee('Μέγεθος / Δόση: Διπλός');
    }

    public function test_the_admin_view_route_returns_404_for_an_unknown_order_id(): void
    {
        $this->get(route('filament.admin.resources.orders.view', ['record' => 999999]))
            ->assertNotFound();
    }
}
