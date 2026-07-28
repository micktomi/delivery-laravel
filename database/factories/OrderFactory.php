<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'display_number' => fake()->numberBetween(1, 999),
            'status' => OrderStatus::Nea->value,
            'payment_method' => PaymentMethod::Cash->value,
            'customer_name' => fake()->name(),
            'phone' => '69'.fake()->numerify('########'),
            'address' => fake()->streetAddress(),
            'floor_bell' => null,
            'notes' => null,
            'subtotal' => '5.00',
            'delivery_fee' => '0.00',
            'total' => '5.00',
            'placed_at' => now(),
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(['status' => $status->value]);
    }
}
