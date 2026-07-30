<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponPricingTest extends TestCase
{
    use RefreshDatabase;

    private function pricing(): PricingService
    {
        return app(PricingService::class);
    }

    /** A €12.40 basket: the worked example from the spec. */
    private function basket(float $lineTotal = 12.40): array
    {
        return [['line_total' => $lineTotal]];
    }

    public function test_percentage_coupon_discounts_the_subtotal(): void
    {
        $coupon = Coupon::factory()->percentage('15.00')->create(['code' => 'WELCOME15']);

        $totals = $this->pricing()->totals($this->basket(), $coupon);

        $this->assertSame(12.40, $totals['subtotal']);
        $this->assertSame(1.86, $totals['discount']);
        $this->assertSame(10.54, $totals['total']);
    }

    public function test_fixed_coupon_discounts_the_subtotal(): void
    {
        $coupon = Coupon::factory()->fixed('5.00')->create();

        $totals = $this->pricing()->totals($this->basket(), $coupon);

        $this->assertSame(5.00, $totals['discount']);
        $this->assertSame(7.40, $totals['total']);
    }

    public function test_fixed_coupon_larger_than_the_subtotal_clamps_to_it(): void
    {
        $coupon = Coupon::factory()->fixed('5.00')->create();

        $totals = $this->pricing()->totals($this->basket(4.00), $coupon);

        $this->assertSame(4.00, $totals['discount']);
        $this->assertSame(0.00, $totals['total']);
    }

    public function test_delivery_fee_is_added_after_the_discount(): void
    {
        $coupon = Coupon::factory()->fixed('5.00')->create();

        $totals = $this->pricing()->totals($this->basket(), $coupon, deliveryFee: 1.50);

        $this->assertSame(5.00, $totals['discount']);
        $this->assertSame(8.90, $totals['total']);
    }

    public function test_no_coupon_leaves_the_subtotal_untouched(): void
    {
        $totals = $this->pricing()->totals($this->basket());

        $this->assertSame(0.00, $totals['discount']);
        $this->assertSame(12.40, $totals['total']);
    }

    public function test_below_minimum_order_total_is_rejected_with_a_reason(): void
    {
        $coupon = Coupon::factory()->create(['min_order_total' => '15.00']);

        $reason = $coupon->rejectionReason(12.40);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('15,00€', $reason);
        $this->assertSame(0.00, $this->pricing()->totals($this->basket(), $coupon)['discount']);
    }

    public function test_minimum_order_total_is_checked_against_the_subtotal_before_the_discount(): void
    {
        $coupon = Coupon::factory()->percentage('50.00')->create(['min_order_total' => '12.00']);

        // A 50% discount would drop the basket under the minimum; that must not
        // disqualify the coupon that produced it.
        $this->assertTrue($coupon->isRedeemableFor(12.40));
        $this->assertSame(6.20, $this->pricing()->totals($this->basket(), $coupon)['discount']);
    }

    public function test_expired_coupon_is_rejected(): void
    {
        $coupon = Coupon::factory()->create(['expires_at' => now()->subMinute()]);

        $this->assertSame('Ο κωδικός έχει λήξει.', $coupon->rejectionReason(12.40));
        $this->assertSame(0.00, $this->pricing()->totals($this->basket(), $coupon)['discount']);
    }

    public function test_not_yet_started_coupon_is_rejected(): void
    {
        $coupon = Coupon::factory()->create(['starts_at' => now()->addDay()]);

        $this->assertSame('Ο κωδικός δεν ισχύει ακόμη.', $coupon->rejectionReason(12.40));
        $this->assertSame(0.00, $this->pricing()->totals($this->basket(), $coupon)['discount']);
    }

    public function test_inactive_coupon_is_rejected(): void
    {
        $coupon = Coupon::factory()->create(['is_active' => false]);

        $this->assertSame('Ο κωδικός δεν είναι ενεργός.', $coupon->rejectionReason(12.40));
        $this->assertSame(0.00, $this->pricing()->totals($this->basket(), $coupon)['discount']);
    }

    public function test_used_up_coupon_is_rejected(): void
    {
        $coupon = Coupon::factory()->create(['max_uses' => 2]);
        $coupon->forceFill(['used_count' => 2])->save();

        $this->assertSame('Ο κωδικός έχει εξαντληθεί.', $coupon->fresh()->rejectionReason(12.40));
    }

    public function test_a_coupon_inside_its_window_is_redeemable(): void
    {
        $coupon = Coupon::factory()->create([
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
            'max_uses' => 10,
        ]);

        $this->assertNull($coupon->rejectionReason(12.40));
    }

    public function test_codes_are_stored_uppercase_and_looked_up_case_insensitively(): void
    {
        Coupon::factory()->create(['code' => 'welcome15']);

        $this->assertDatabaseHas('coupons', ['code' => 'WELCOME15']);
        $this->assertNotNull(Coupon::findByCode(' welcome15 '));
        $this->assertNull(Coupon::findByCode('NOPE'));
        $this->assertNull(Coupon::findByCode(null));
        $this->assertNull(Coupon::findByCode('   '));
    }

    public function test_claim_stops_at_the_usage_cap(): void
    {
        $coupon = Coupon::factory()->create(['max_uses' => 1]);

        $this->assertTrue($coupon->claim());
        $this->assertFalse(Coupon::find($coupon->id)->claim());
        $this->assertSame(1, Coupon::find($coupon->id)->used_count);
    }

    public function test_concurrent_claims_of_the_last_use_let_exactly_one_through(): void
    {
        $coupon = Coupon::factory()->create(['max_uses' => 3]);
        $coupon->forceFill(['used_count' => 2])->save();

        // Two requests that both read the row before either wrote it: the naive
        // used_count < max_uses check passes for both.
        $first = Coupon::find($coupon->id);
        $second = Coupon::find($coupon->id);

        $this->assertTrue($first->isRedeemableFor(12.40));
        $this->assertTrue($second->isRedeemableFor(12.40));

        $results = [$first->claim(), $second->claim()];

        $this->assertSame([true, false], $results);
        $this->assertSame(3, Coupon::find($coupon->id)->used_count);
    }

    public function test_an_uncapped_coupon_can_always_be_claimed(): void
    {
        $coupon = Coupon::factory()->create(['max_uses' => null]);

        $this->assertTrue($coupon->claim());
        $this->assertTrue($coupon->fresh()->claim());
        $this->assertSame(2, $coupon->fresh()->used_count);
    }
}
