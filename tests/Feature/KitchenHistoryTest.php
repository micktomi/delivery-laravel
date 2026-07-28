<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Livewire\KitchenHistory;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KitchenHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_kitchen_history(): void
    {
        $this->get('/kitchen/history')
            ->assertRedirect('/admin/login');
    }

    public function test_authenticated_user_can_access_kitchen_history(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/kitchen/history')
            ->assertOk();
    }

    public function test_orders_appear_in_history_and_revenue_is_calculated_correctly(): void
    {
        $user = User::factory()->create();

        // 1. Create a Completed order (should show in history and count towards revenue)
        $completedOrder = Order::factory()->create([
            'status' => OrderStatus::Completed->value,
            'total' => 10.50,
            'created_at' => now(),
        ]);

        // 2. Create a Sent (Out) order (should show in history and count towards revenue)
        $sentOrder = Order::factory()->create([
            'status' => OrderStatus::Out->value,
            'total' => 15.00,
            'created_at' => now(),
        ]);

        // 3. Create a Cancelled order (should show in history but NOT count towards revenue)
        $cancelledOrder = Order::factory()->create([
            'status' => OrderStatus::Cancelled->value,
            'total' => 20.00,
            'created_at' => now(),
        ]);

        // 4. Create a New order (should NOT show in history but counts as total order of today)
        $newOrder = Order::factory()->create([
            'status' => OrderStatus::Nea->value,
            'total' => 5.00,
            'created_at' => now(),
        ]);

        // 5. Create an order from yesterday (should NOT show in history or count in today's metrics)
        $yesterdayOrder = Order::factory()->create([
            'status' => OrderStatus::Completed->value,
            'total' => 100.00,
            'created_at' => now()->subDay(),
        ]);

        // Livewire test on KitchenHistory component
        Livewire::actingAs($user)
            ->test(KitchenHistory::class)
            ->assertViewHas('totalOrdersCount', 4) // completed, sent, cancelled, new (all today)
            ->assertViewHas('completedOrSentCount', 2) // completed + sent
            ->assertViewHas('cancelledCount', 1) // cancelled
            ->assertViewHas('dailyRevenue', 30.50) // 10.50 + 15.00 + 5.00 = 30.50 (new order + completed + sent, non-cancelled)
            ->assertSee($completedOrder->customer_name)
            ->assertSee($sentOrder->customer_name)
            ->assertSee($cancelledOrder->customer_name)
            ->assertDontSee($yesterdayOrder->customer_name);
    }
}
