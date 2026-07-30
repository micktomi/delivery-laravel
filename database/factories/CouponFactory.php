<?php

namespace Database\Factories;

use App\Enums\CouponType;
use Illuminate\Database\Eloquent\Factories\Factory;

class CouponFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'WELCOME'.fake()->unique()->numberBetween(1, 9999),
            'type' => CouponType::Percentage->value,
            'value' => '15.00',
            'min_order_total' => null,
            'starts_at' => null,
            'expires_at' => null,
            'max_uses' => null,
            'is_active' => true,
        ];
    }

    public function fixed(string $value = '5.00'): static
    {
        return $this->state([
            'type' => CouponType::Fixed->value,
            'value' => $value,
        ]);
    }

    public function percentage(string $value = '15.00'): static
    {
        return $this->state([
            'type' => CouponType::Percentage->value,
            'value' => $value,
        ]);
    }
}
