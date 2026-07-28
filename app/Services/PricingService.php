<?php

namespace App\Services;

class PricingService
{
    public function lineTotal(float $basePrice, array $selectedDeltas, int $qty): float
    {
        $unitPrice = $basePrice + array_sum($selectedDeltas);

        return round($unitPrice * $qty, 2);
    }

    public function subtotal(array $cartItems): float
    {
        return round(array_sum(array_column($cartItems, 'line_total')), 2);
    }

    public function total(float $subtotal, float $deliveryFee): float
    {
        return round($subtotal + $deliveryFee, 2);
    }
}
