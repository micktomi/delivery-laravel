<?php

namespace App\Actions;

use App\Enums\OrderStatus;
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
        return DB::transaction(function () use ($checkoutData) {
            $cartItems = $this->verifiedLines();

            $subtotal = $this->pricing->subtotal($cartItems);
            $deliveryFee = 0.00;
            $total = $this->pricing->total($subtotal, $deliveryFee);

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
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
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

            $this->cart->clear();

            Log::info('order.created', [
                'order_id' => $order->id,
                'display_number' => $order->display_number,
                'items' => count($cartItems),
                'total' => $total,
                'payment_method' => $order->payment_method->value,
            ]);

            return $order;
        });
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
