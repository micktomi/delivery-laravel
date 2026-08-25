<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Product;
use Illuminate\Support\Facades\Session;

class CartService
{
    private const KEY = 'cart';

    public const MAX_QUANTITY = 99;

    public function items(): array
    {
        return $this->state()['lines'];
    }

    /**
     * Remove session lines that the customer menu can no longer order.
     *
     * The Product::available() scope is the same catalogue rule used by the
     * menu query and add-to-cart lookup. Checkout still performs its full
     * verification in case availability changes again after this refresh.
     */
    public function removeUnavailableProducts(): void
    {
        $lines = $this->items();

        if ($lines === []) {
            return;
        }

        $productIds = array_values(array_unique(array_map(
            'intval',
            array_column($lines, 'product_id'),
        )));

        $availableProductIds = Product::query()
            ->available()
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->whereKey($productIds)
            ->pluck('id')
            ->mapWithKeys(fn (int $id): array => [$id => true]);

        $availableLines = array_values(array_filter(
            $lines,
            fn (array $line): bool => isset($availableProductIds[(int) ($line['product_id'] ?? 0)]),
        ));

        if (count($availableLines) !== count($lines)) {
            $this->replace($availableLines);
        }
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
        $this->putLines($cart);
    }

    public function update(int $index, int $qty): void
    {
        $cart = $this->items();
        if (! isset($cart[$index])) {
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

        $this->putLines($cart);
    }

    public function adjustQuantity(int $index, int $delta): void
    {
        $cart = $this->items();
        if (! isset($cart[$index])) {
            return;
        }

        $qty = (int) $cart[$index]['quantity'] + $delta;

        if ($qty < 1) {
            $this->remove($index);

            return;
        }

        $this->update($index, $qty);
    }

    public function remove(int $index): void
    {
        $cart = $this->items();
        array_splice($cart, $index, 1);
        $this->putLines($cart);
    }

    /**
     * Overwrite the cart with server-verified lines (availability / current pricing).
     */
    public function replace(array $lines): void
    {
        $this->putLines($lines);
    }

    /**
     * Drops the coupon with the lines: they are one session value, so a
     * discount can never outlive the basket that earned it.
     */
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

    public function couponCode(): ?string
    {
        return $this->state()['coupon_code'];
    }

    public function coupon(): ?Coupon
    {
        return Coupon::findByCode($this->couponCode());
    }

    public function applyCoupon(Coupon $coupon): void
    {
        $this->put($this->items(), $coupon->code);
    }

    public function removeCoupon(): void
    {
        $this->put($this->items(), null);
    }

    /**
     * @return array{subtotal: float, discount: float, total: float}
     */
    public function totals(float $deliveryFee = 0.0): array
    {
        return app(PricingService::class)->totals($this->items(), $this->coupon(), $deliveryFee);
    }

    /**
     * Quantities arrive from the browser, so they are never trusted as-is.
     */
    public function normalizeQuantity(mixed $qty): int
    {
        return max(1, min(self::MAX_QUANTITY, (int) $qty));
    }

    /**
     * @return array{lines: array, coupon_code: ?string}
     */
    private function state(): array
    {
        $state = Session::get(self::KEY, []);

        if (! is_array($state)) {
            return ['lines' => [], 'coupon_code' => null];
        }

        // A cart stored before coupons existed is a bare list of lines.
        if (! array_key_exists('lines', $state)) {
            return ['lines' => array_values($state), 'coupon_code' => null];
        }

        $code = $state['coupon_code'] ?? null;

        return [
            'lines' => array_values((array) ($state['lines'] ?? [])),
            'coupon_code' => is_string($code) && $code !== '' ? $code : null,
        ];
    }

    private function putLines(array $lines): void
    {
        $this->put($lines, $this->couponCode());
    }

    private function put(array $lines, ?string $couponCode): void
    {
        Session::put(self::KEY, [
            'lines' => array_values($lines),
            'coupon_code' => $couponCode,
        ]);
    }
}
