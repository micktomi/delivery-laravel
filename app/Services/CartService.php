<?php

namespace App\Services;

use Illuminate\Support\Facades\Session;

class CartService
{
    private const KEY = 'cart';

    public const MAX_QUANTITY = 99;

    public function items(): array
    {
        return Session::get(self::KEY, []);
    }

    public function add(array $line): void
    {
        $line['quantity'] = $this->normalizeQuantity($line['quantity'] ?? 1);
        $line['line_total'] = app(PricingService::class)->lineTotal(
            (float) $line['base_price'],
            array_column($line['selected_options'] ?? [], 'price_delta'),
            $line['quantity'],
        );

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
        $qty = $this->normalizeQuantity($qty);
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

    /**
     * Overwrite the cart with server-verified lines (availability / current pricing).
     */
    public function replace(array $lines): void
    {
        Session::put(self::KEY, array_values($lines));
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

    /**
     * Quantities arrive from the browser, so they are never trusted as-is.
     */
    public function normalizeQuantity(mixed $qty): int
    {
        return max(1, min(self::MAX_QUANTITY, (int) $qty));
    }
}
