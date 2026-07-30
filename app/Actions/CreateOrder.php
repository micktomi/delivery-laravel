<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\CartService;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateOrder
{
    public function __construct(
        private CartService $cart,
        private PricingService $pricing,
    ) {}

    public function execute(array $checkoutData): Order
    {
        $order = DB::transaction(function () use ($checkoutData) {
            $cartItems = $this->verifiedLines();

            $subtotal = $this->pricing->subtotal($cartItems);
            $deliveryFee = 0.00;

            // Nothing about the coupon is taken from the session or the browser
            // beyond its code: the row is re-read, re-checked against the price
            // the customer is actually about to pay, and claimed atomically.
            $coupon = $this->redeemableCoupon($subtotal);
            $totals = $this->pricing->totals($cartItems, $coupon, $deliveryFee);

            if ($coupon && ! $coupon->claim()) {
                // The last use went to someone else between validation and now.
                Log::info('order.coupon_lost_race', ['code' => $coupon->code]);
                $coupon = null;
                $totals = $this->pricing->totals($cartItems, null, $deliveryFee);
            }

            $displayNumber = DB::table('orders')
                ->whereDate('created_at', today())
                ->lockForUpdate()
                ->count() + 1;

            $order = Order::create([
                'display_number' => $displayNumber,
                'status' => OrderStatus::Nea->value,
                'payment_method' => $checkoutData['payment_method'],
                'customer_name' => $checkoutData['customer_name'],
                'phone' => $checkoutData['phone'],
                'address' => $checkoutData['address'],
                'floor_bell' => $checkoutData['floor_bell'] ?? null,
                'notes' => $checkoutData['notes'] ?? null,
                'subtotal' => $totals['subtotal'],
                'delivery_fee' => $deliveryFee,
                // Snapshots: what the courier collects must survive any later
                // edit or deletion of the coupon itself.
                'coupon_code' => $coupon?->code,
                'discount_amount' => $coupon ? $totals['discount'] : 0.00,
                'coupon_id' => $coupon?->id,
                'total' => $totals['total'],
                'placed_at' => now(),
            ]);

            foreach ($cartItems as $line) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'product_name' => $line['product_name'],
                    'base_price' => $line['base_price'],
                    'quantity' => $line['quantity'],
                    'selected_options' => $line['selected_options'],
                    'line_total' => $line['line_total'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $order;
        });

        // Only once the order is committed: a rollback must leave the customer
        // holding the same basket, coupon included.
        $this->cart->clear();

        Log::info('order.created', [
            'order_id' => $order->id,
            'display_number' => $order->display_number,
            'items' => $order->items()->count(),
            'total' => $order->total,
            'coupon_code' => $order->coupon_code,
            'discount' => $order->discount_amount,
            'payment_method' => $order->payment_method->value,
        ]);

        return $order;
    }

    /**
     * The coupon as it stands right now, or null if it no longer qualifies. A
     * code that expired, was switched off or ran out between the cart and this
     * moment costs the discount — never the order.
     */
    private function redeemableCoupon(float $subtotal): ?Coupon
    {
        $code = $this->cart->couponCode();

        if ($code === null) {
            return null;
        }

        $coupon = Coupon::findByCode($code);

        if ($coupon === null) {
            Log::info('order.coupon_dropped', ['code' => $code, 'reason' => 'unknown code']);

            return null;
        }

        $reason = $coupon->rejectionReason($subtotal);

        if ($reason !== null) {
            Log::info('order.coupon_dropped', ['code' => $code, 'reason' => $reason]);

            return null;
        }

        return $coupon;
    }

    /**
     * Re-check the cart against the catalogue before anything is charged: the
     * session may be up to SESSION_LIFETIME minutes old and every value in it
     * originally came from a browser.
     *
     * @throws ValidationException when the cart no longer matches the catalogue
     */
    private function verifiedLines(): array
    {
        $cartItems = $this->cart->items();

        if ($cartItems === []) {
            throw ValidationException::withMessages([
                'cart' => 'Το καλάθι σας είναι άδειο.',
            ]);
        }

        $products = Product::query()
            ->whereIn('id', array_column($cartItems, 'product_id'))
            ->get()
            ->keyBy('id');

        $optionValues = OptionValue::query()
            ->whereIn('id', $this->referencedOptionValueIds($cartItems))
            ->get()
            ->keyBy('id');

        $unavailable = [];
        $verified = [];
        $changed = false;

        foreach ($cartItems as $line) {
            $product = $products->get((int) ($line['product_id'] ?? 0));

            if (! $product || ! $product->is_available) {
                $unavailable[] = $line['product_name'] ?? 'προϊόν';
                $changed = true;

                continue;
            }

            [$options, $deltas] = $this->currentOptions($line['selected_options'] ?? [], $optionValues);

            $quantity = $this->cart->normalizeQuantity($line['quantity'] ?? 1);
            $basePrice = (float) $product->base_price;
            $lineTotal = $this->pricing->lineTotal($basePrice, $deltas, $quantity);

            if ($quantity !== (int) ($line['quantity'] ?? 0)
                || abs($lineTotal - (float) ($line['line_total'] ?? 0)) >= 0.005) {
                $changed = true;
            }

            $verified[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'base_price' => $basePrice,
                'selected_options' => $options,
                'quantity' => $quantity,
                'line_total' => $lineTotal,
                'notes' => $line['notes'] ?? null,
            ];
        }

        if ($changed) {
            // The customer must see and accept the corrected cart before it is charged.
            $this->cart->replace($verified);

            Log::warning('order.cart_corrected', [
                'unavailable' => $unavailable,
                'lines_kept' => count($verified),
            ]);

            throw ValidationException::withMessages([
                'cart' => $unavailable === []
                    ? 'Ο τιμοκατάλογος άλλαξε. Ελέγξτε το ενημερωμένο καλάθι και υποβάλετε ξανά.'
                    : 'Δεν είναι πλέον διαθέσιμα: '.implode(', ', $unavailable)
                        .'. Το καλάθι ενημερώθηκε — ελέγξτε το και υποβάλετε ξανά.',
            ]);
        }

        return $verified;
    }

    /**
     * Re-resolve each chosen option against the catalogue so the price that is
     * charged is today's price, not the one captured when the cart was built.
     */
    private function currentOptions(array $selectedOptions, $optionValues): array
    {
        $options = [];
        $deltas = [];

        foreach ($selectedOptions as $option) {
            $value = isset($option['option_value_id'])
                ? $optionValues->get((int) $option['option_value_id'])
                : null;

            if ($value) {
                $delta = (float) $value->price_delta;
                $options[] = [
                    'option_value_id' => $value->id,
                    'group' => $option['group'] ?? '',
                    'value' => $value->name,
                    'price_delta' => $delta,
                ];
            } else {
                // Cart line built before option ids were stored: keep its snapshot.
                $delta = (float) ($option['price_delta'] ?? 0);
                $options[] = $option;
            }

            $deltas[] = $delta;
        }

        return [$options, $deltas];
    }

    private function referencedOptionValueIds(array $cartItems): array
    {
        $ids = [];

        foreach ($cartItems as $line) {
            foreach ($line['selected_options'] ?? [] as $option) {
                if (isset($option['option_value_id'])) {
                    $ids[] = (int) $option['option_value_id'];
                }
            }
        }

        return array_unique($ids);
    }
}
