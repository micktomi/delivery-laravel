<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PricingService works in integer cents. These pin the boundaries where float
 * arithmetic would have had to be trusted: half-cent rounding, repeated
 * addition of values with no exact binary form, and the clamp.
 */
class PricingRoundingTest extends TestCase
{
    use RefreshDatabase;

    private function pricing(): PricingService
    {
        return app(PricingService::class);
    }

    private function lines(float ...$lineTotals): array
    {
        return array_map(fn ($total) => ['line_total' => $total], $lineTotals);
    }

    public function test_repeated_addition_of_inexact_values_stays_exact(): void
    {
        // 0.1 and 0.2 have no exact binary form; ten of them must be exactly 1.
        $this->assertSame(1.00, $this->pricing()->subtotal($this->lines(...array_fill(0, 10, 0.10))));
        $this->assertSame(3.00, $this->pricing()->subtotal($this->lines(...array_fill(0, 10, 0.30))));
        $this->assertSame(0.30, $this->pricing()->subtotal($this->lines(0.10, 0.20)));
    }

    public function test_option_deltas_accumulate_exactly(): void
    {
        $this->assertSame(0.90, $this->pricing()->lineTotal(0.10, [0.20], 3));
        $this->assertSame(10.50, $this->pricing()->lineTotal(2.80, [0.50, 0.20], 3));
        $this->assertSame(27.90, $this->pricing()->lineTotal(2.80, [0.10, 0.20], 9));
    }

    public function test_a_half_cent_percentage_rounds_up(): void
    {
        $coupon = Coupon::factory()->percentage('15.00')->create();

        // 15% of 12.30 is exactly 1.845.
        $this->assertSame(1.85, $this->pricing()->totals($this->lines(12.30), $coupon)['discount']);
        $this->assertSame(10.45, $this->pricing()->totals($this->lines(12.30), $coupon)['total']);
    }

    public function test_a_sub_cent_percentage_rounds_to_the_nearest_cent(): void
    {
        $tenPercent = Coupon::factory()->percentage('10.00')->create();
        $third = Coupon::factory()->percentage('33.33')->create();

        // 10% of 0.35 is 0.035 — the classic case where the float sits just
        // below the half and would round down.
        $this->assertSame(0.04, $this->pricing()->totals($this->lines(0.35), $tenPercent)['discount']);
        $this->assertSame(0.31, $this->pricing()->totals($this->lines(0.35), $tenPercent)['total']);

        // 33.33% of 10.00 is 3.333 — rounds down.
        $this->assertSame(3.33, $this->pricing()->totals($this->lines(10.00), $third)['discount']);
        $this->assertSame(6.67, $this->pricing()->totals($this->lines(10.00), $third)['total']);
    }

    public function test_a_fractional_percentage_rate_is_honoured(): void
    {
        $coupon = Coupon::factory()->percentage('7.50')->create();

        $this->assertSame(1.49, $this->pricing()->totals($this->lines(19.90), $coupon)['discount']);
        $this->assertSame(18.41, $this->pricing()->totals($this->lines(19.90), $coupon)['total']);
    }

    public function test_the_three_figures_always_reconcile(): void
    {
        $coupon = Coupon::factory()->percentage('15.00')->create();

        foreach ([[0.35, 0.0], [12.30, 0.0], [12.40, 1.50], [99.99, 2.30], [4.00, 0.05]] as [$line, $fee]) {
            $totals = $this->pricing()->totals($this->lines($line), $coupon, $fee);

            $this->assertSame(
                (int) round($totals['total'] * 100),
                (int) round(($totals['subtotal'] - $totals['discount'] + $fee) * 100),
                "subtotal − discount + fee must equal total for {$line} with fee {$fee}",
            );
        }
    }

    public function test_the_clamp_lands_exactly_on_zero(): void
    {
        $coupon = Coupon::factory()->fixed('5.00')->create();

        $totals = $this->pricing()->totals($this->lines(0.10, 0.20), $coupon);

        $this->assertSame(0.30, $totals['discount']);
        $this->assertSame(0.00, $totals['total']);
        $this->assertSame(0.0, $totals['total']);
    }

    public function test_a_hundred_percent_coupon_lands_exactly_on_zero(): void
    {
        $coupon = Coupon::factory()->percentage('100.00')->create();

        $totals = $this->pricing()->totals($this->lines(12.37), $coupon);

        $this->assertSame(12.37, $totals['discount']);
        $this->assertSame(0.00, $totals['total']);
    }

    public function test_the_delivery_fee_is_never_discounted(): void
    {
        $coupon = Coupon::factory()->percentage('100.00')->create();

        $totals = $this->pricing()->totals($this->lines(12.37), $coupon, deliveryFee: 1.50);

        $this->assertSame(12.37, $totals['discount']);
        $this->assertSame(1.50, $totals['total']);
    }
}
