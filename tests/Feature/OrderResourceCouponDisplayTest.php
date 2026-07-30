<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The order view has to show what was actually collected and why it was lower,
 * from the snapshot alone — the coupon behind it may have changed or gone.
 */
class OrderResourceCouponDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    private function discountedOrder(?Coupon $coupon = null): Order
    {
        return Order::factory()->create([
            'subtotal' => '12.40',
            'coupon_code' => 'WELCOME15',
            'discount_amount' => '1.86',
            'coupon_id' => $coupon?->id,
            'total' => '10.54',
        ]);
    }

    public function test_the_order_view_shows_subtotal_discount_and_total(): void
    {
        $order = $this->discountedOrder();

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSee('Υποσύνολο')
            ->assertSee('€12.40')
            ->assertSee('Κουπόνι')
            ->assertSee('WELCOME15')
            ->assertSee('Έκπτωση')
            ->assertSee('€1.86')
            ->assertSee('Σύνολο')
            ->assertSee('€10.54');
    }

    public function test_an_order_without_a_coupon_shows_no_discount(): void
    {
        $order = Order::factory()->create([
            'subtotal' => '12.40',
            'total' => '12.40',
        ]);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSee('€12.40')
            ->assertSee('€0.00')
            ->assertDontSee('WELCOME15');
    }

    public function test_the_view_survives_the_coupon_being_deleted(): void
    {
        $coupon = Coupon::factory()->percentage('15.00')->create(['code' => 'WELCOME15']);
        $order = $this->discountedOrder($coupon);

        $coupon->delete();

        $order->refresh();
        $this->assertNull($order->coupon_id);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSee('WELCOME15')
            ->assertSee('€1.86')
            ->assertSee('€10.54');
    }
}
