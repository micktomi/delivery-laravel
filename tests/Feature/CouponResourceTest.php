<?php

namespace Tests\Feature;

use App\Enums\CouponType;
use App\Filament\Resources\CouponResource;
use App\Filament\Resources\CouponResource\Pages\CreateCoupon;
use App\Filament\Resources\CouponResource\Pages\EditCoupon;
use App\Filament\Resources\CouponResource\Pages\ListCoupons;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CouponResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    private function formData(array $overrides = []): array
    {
        return array_merge([
            'code' => 'WELCOME15',
            'type' => CouponType::Percentage->value,
            'value' => 15,
            'min_order_total' => null,
            'starts_at' => null,
            'expires_at' => null,
            'max_uses' => null,
            'is_active' => true,
        ], $overrides);
    }

    public function test_the_owner_can_create_a_coupon(): void
    {
        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['max_uses' => 50, 'min_order_total' => 8]))
            ->call('create')
            ->assertHasNoFormErrors();

        $coupon = Coupon::firstWhere('code', 'WELCOME15');

        $this->assertNotNull($coupon);
        $this->assertSame(CouponType::Percentage, $coupon->type);
        $this->assertSame('15.00', $coupon->value);
        $this->assertSame('8.00', $coupon->min_order_total);
        $this->assertSame(50, $coupon->max_uses);
        $this->assertSame(0, $coupon->used_count);
        $this->assertTrue($coupon->is_active);
    }

    public function test_a_code_typed_in_lowercase_is_stored_uppercase(): void
    {
        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['code' => ' welcome15 ']))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('coupons', ['code' => 'WELCOME15']);
        $this->assertDatabaseCount('coupons', 1);
    }

    public function test_the_same_code_in_another_case_is_refused_as_a_duplicate(): void
    {
        Coupon::factory()->create(['code' => 'WELCOME15']);

        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['code' => 'welcome15']))
            ->call('create')
            ->assertHasFormErrors(['code']);

        $this->assertDatabaseCount('coupons', 1);
    }

    public function test_a_percentage_over_one_hundred_is_refused(): void
    {
        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['value' => 150]))
            ->call('create')
            ->assertHasFormErrors(['value']);

        $this->assertDatabaseCount('coupons', 0);
    }

    public function test_a_percentage_of_exactly_one_hundred_is_allowed(): void
    {
        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['code' => 'DOro', 'value' => 100]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('100.00', Coupon::firstWhere('code', 'DORO')->value);
    }

    /** The cap is on percentages only: €150 off a large order is legitimate. */
    public function test_a_fixed_amount_over_one_hundred_is_allowed(): void
    {
        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData([
                'code' => 'CATERING',
                'type' => CouponType::Fixed->value,
                'value' => 150,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('150.00', Coupon::firstWhere('code', 'CATERING')->value);
    }

    public function test_a_negative_value_is_refused(): void
    {
        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData(['value' => -5]))
            ->call('create')
            ->assertHasFormErrors(['value']);
    }

    public function test_an_expiry_before_the_start_is_refused(): void
    {
        Livewire::test(CreateCoupon::class)
            ->fillForm($this->formData([
                'starts_at' => now()->addWeek(),
                'expires_at' => now()->addDay(),
            ]))
            ->call('create')
            ->assertHasFormErrors(['expires_at']);
    }

    public function test_the_list_shows_how_much_of_a_coupon_is_left(): void
    {
        $capped = Coupon::factory()->create(['code' => 'LIGA', 'max_uses' => 10]);
        $capped->forceFill(['used_count' => 7])->save();

        Coupon::factory()->create(['code' => 'APERIORISTO', 'max_uses' => null]);

        Livewire::test(ListCoupons::class)
            ->assertCanSeeTableRecords([$capped])
            ->assertSee('7 / 10')
            ->assertSee('0 / ∞');
    }

    public function test_the_usage_count_cannot_be_edited_by_hand(): void
    {
        $coupon = Coupon::factory()->create(['max_uses' => 10]);
        $coupon->forceFill(['used_count' => 3])->save();

        Livewire::test(EditCoupon::class, ['record' => $coupon->getKey()])
            ->fillForm(['value' => 20])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(3, $coupon->fresh()->used_count);
        $this->assertSame('20.00', $coupon->fresh()->value);
    }

    /**
     * The whole point of the snapshot: the owner drops a coupon from 15% to 5%
     * and yesterday's orders keep the money they were placed with.
     */
    public function test_editing_a_coupon_leaves_past_orders_untouched(): void
    {
        $coupon = Coupon::factory()->percentage('15.00')->create(['code' => 'WELCOME15']);

        $order = Order::factory()->create([
            'subtotal' => '12.40',
            'coupon_code' => 'WELCOME15',
            'discount_amount' => '1.86',
            'coupon_id' => $coupon->id,
            'total' => '10.54',
        ]);

        Livewire::test(EditCoupon::class, ['record' => $coupon->getKey()])
            ->fillForm(['value' => 5, 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $order->refresh();

        $this->assertSame('1.86', $order->discount_amount);
        $this->assertSame('10.54', $order->total);
        $this->assertSame('WELCOME15', $order->coupon_code);
    }

    public function test_kitchen_staff_cannot_reach_the_coupon_screens(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(CouponResource::getUrl('index'))->assertForbidden();
    }
}
