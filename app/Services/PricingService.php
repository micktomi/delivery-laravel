<?php

namespace App\Services;

use App\Enums\CouponType;
use App\Models\Coupon;
use InvalidArgumentException;

/**
 * Every figure here is computed in integer cents and only converted back to
 * euros on the way out. Money is exact by construction rather than by trusting
 * round() to clean up binary fractions after each step.
 */
class PricingService
{
    public function lineTotal(float $basePrice, array $selectedDeltas, int $qty): float
    {
        $unitCents = $this->cents($basePrice);

        foreach ($selectedDeltas as $delta) {
            $unitCents += $this->cents((float) $delta);
        }

        if ($unitCents < 0) {
            throw new InvalidArgumentException('The final unit price cannot be negative.');
        }

        return $this->euros($unitCents * $qty);
    }

    public function subtotal(array $cartItems): float
    {
        $cents = 0;

        foreach ($cartItems as $item) {
            $cents += $this->cents((float) ($item['line_total'] ?? 0));
        }

        return $this->euros($cents);
    }

    public function total(float $subtotal, float $deliveryFee): float
    {
        return $this->euros($this->cents($subtotal) + $this->cents($deliveryFee));
    }

    /**
     * Cent-exact comparison, so a subtotal that equals the minimum to the
     * cent (never mind a stray binary-float remainder) is never rejected.
     */
    public function meetsMinimum(float $subtotal, float $minimum): bool
    {
        return $this->cents($subtotal) >= $this->cents($minimum);
    }

    /**
     * The three figures that must always be shown together: what the basket
     * costs, what the coupon takes off, and what the courier collects.
     *
     * @return array{subtotal: float, discount: float, total: float}
     */
    public function totals(array $cartItems, ?Coupon $coupon = null, float $deliveryFee = 0.0): array
    {
        $subtotalCents = $this->cents($this->subtotal($cartItems));
        $discountCents = $this->discountCents($subtotalCents, $coupon);

        return [
            'subtotal' => $this->euros($subtotalCents),
            'discount' => $this->euros($discountCents),
            // The delivery fee is never discounted: it is the courier's, not the café's.
            'total' => $this->euros($subtotalCents - $discountCents + $this->cents($deliveryFee)),
        ];
    }

    /**
     * A coupon that cannot be redeemed is worth nothing here too, so a stale
     * one carried in the session can never move a price on its own.
     */
    private function discountCents(int $subtotalCents, ?Coupon $coupon): int
    {
        if (! $coupon || ! $coupon->isRedeemableFor($this->euros($subtotalCents))) {
            return 0;
        }

        $valueCents = $this->cents((float) $coupon->value);

        return match ($coupon->type) {
            // value is a percentage with 2 decimals, so 15.00% is 1500 hundredths.
            CouponType::Percentage => (int) round($subtotalCents * $valueCents / 10000),
            // Clamp: a €5 coupon on a €4 basket gives €4 off, never a negative total.
            CouponType::Fixed => min($valueCents, $subtotalCents),
        };
    }

    private function cents(float $euros): int
    {
        return (int) round($euros * 100);
    }

    private function euros(int $cents): float
    {
        return $cents / 100;
    }
}
