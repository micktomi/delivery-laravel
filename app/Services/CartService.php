<?php

namespace App\Services;

use Illuminate\Support\Facades\Session;

class CartService
{
    private const KEY = 'cart';

    public function items(): array
    {
        return Session::get(self::KEY, []);
    }

    public function add(array $line): void
    {
        $cart = $this->items();
        $cart[] = $line;
        Session::put(self::KEY, $cart);
    }

    public function update(int $index, int $qty): void
    {
        $cart = $this->items();
        if (!isset($cart[$index])) {
            return;
        }

        $pricing = app(PricingService::class);
        $deltas = array_column($cart[$index]['selected_options'], 'price_delta');
        $cart[$index]['quantity'] = $qty;
        $cart[$index]['line_total'] = $pricing->lineTotal(
            (float) $cart[$index]['base_price'],
            $deltas,
            $qty
        );

        Session::put(self::KEY, $cart);
    }

    public function remove(int $index): void
    {
        $cart = $this->items();
        array_splice($cart, $index, 1);
        Session::put(self::KEY, array_values($cart));
    }

    public function clear(): void
    {
        Session::forget(self::KEY);
    }

    public function isEmpty(): bool
    {
        return empty($this->items());
    }

    public function subtotal(): float
    {
        return app(PricingService::class)->subtotal($this->items());
    }
}
