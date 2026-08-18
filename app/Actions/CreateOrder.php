<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SelectionType;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\CartService;
use App\Services\OptionsPresenter;
use App\Services\PricingService;
use App\Support\StoreSchedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class CreateOrder
{
    public function __construct(
        private CartService $cart,
        private PricingService $pricing,
    ) {}

    public function execute(array $checkoutData): Order
    {
        $this->assertStoreAcceptingOrders();

        $checkoutToken = $checkoutData['checkout_token'] ?? (string) Str::uuid();

        if (! is_string($checkoutToken) || ! Str::isUuid($checkoutToken)) {
            throw ValidationException::withMessages([
                'checkout' => 'Η υποβολή της παραγγελίας δεν είναι έγκυρη. Ανανεώστε τη σελίδα.',
            ]);
        }

        $checkoutToken = strtolower($checkoutToken);
        $existingOrder = Order::query()->where('checkout_token', $checkoutToken)->first();

        if ($existingOrder) {
            $this->clearCartAfterCommit($existingOrder);

            return $existingOrder;
        }

        try {
            [$order, $itemCount] = DB::transaction(function () use ($checkoutData, $checkoutToken) {
                $cartItems = $this->verifiedLines();

                $subtotal = $this->pricing->subtotal($cartItems);

                // Measured on the bare subtotal — before delivery fee, payment
                // surcharges or the coupon discount — so a coupon can never be
                // used to duck under the floor for eligibility.
                $this->assertMeetsMinimumOrder($subtotal);

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
                    'checkout_token' => $checkoutToken,
                    'payment_status' => $checkoutData['payment_method'] === PaymentMethod::Viva->value
                        ? 'pending'
                        : null,
                    'customer_name' => $checkoutData['customer_name'],
                    'customer_email' => $checkoutData['customer_email'] ?? null,
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

                return [$order, count($cartItems)];
            });
        } catch (QueryException $e) {
            // A concurrent request with the same signed Livewire snapshot may
            // win the unique checkout_token insert while this transaction waits.
            $order = Order::query()->where('checkout_token', $checkoutToken)->first();

            if (! $order) {
                throw $e;
            }

            $this->clearCartAfterCommit($order);

            return $order;
        }

        $this->clearCartAfterCommit($order);
        $this->logCreatedOrder($order, $itemCount);

        return $order;
    }

    private function clearCartAfterCommit(Order $order): void
    {
        try {
            // A rollback must leave the customer holding the same basket.
            $this->cart->clear();
        } catch (Throwable $e) {
            $this->bestEffortError('order.cart_clear_failed', $order, $e);
        }
    }

    private function logCreatedOrder(Order $order, int $itemCount): void
    {
        try {
            Log::info('order.created', [
                'order_id' => $order->id,
                'display_number' => $order->display_number,
                'items' => $itemCount,
                'total' => $order->total,
                'coupon_code' => $order->coupon_code,
                'discount' => $order->discount_amount,
                'payment_method' => $order->payment_method->value,
            ]);
        } catch (Throwable $e) {
            $this->bestEffortError('order.created_log_failed', $order, $e);
        }
    }

    private function bestEffortError(string $event, Order $order, Throwable $e): void
    {
        try {
            Log::error($event, [
                'order_id' => $order->getKey(),
                'exception' => $e::class,
            ]);
        } catch (Throwable) {
            // The order is committed; an unavailable logger cannot change that truth.
        }
    }

    private function assertStoreAcceptingOrders(): void
    {
        $storeSchedule = app(StoreSchedule::class);

        if ($storeSchedule->isAcceptingOrders()) {
            return;
        }

        throw ValidationException::withMessages([
            'checkout' => $storeSchedule->closedCheckoutMessage(),
        ]);
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
     * @throws ValidationException when the subtotal is below the configured floor
     */
    private function assertMeetsMinimumOrder(float $subtotal): void
    {
        $minimum = (float) config('cart.minimum_order_amount', 5.00);

        if ($this->pricing->meetsMinimum($subtotal, $minimum)) {
            return;
        }

        $remaining = round(max(0.0, $minimum - $subtotal), 2);

        throw ValidationException::withMessages([
            'cart' => 'Χρειάζονται ακόμη '.number_format($remaining, 2, ',', '.')
                .' € για να ολοκληρώσετε την παραγγελία.',
        ]);
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
            ->with(['category', 'optionGroups.optionValues'])
            ->whereIn('id', array_column($cartItems, 'product_id'))
            ->get()
            ->keyBy('id');

        $unavailable = [];
        $verified = [];
        $changed = false;

        foreach ($cartItems as $line) {
            $product = $products->get((int) ($line['product_id'] ?? 0));

            if (! $product || ! $product->is_available || ! $product->category?->is_active) {
                $unavailable[] = $line['product_name'] ?? 'προϊόν';
                $changed = true;

                continue;
            }

            [$options, $deltas] = $this->currentOptions($line['selected_options'] ?? [], $product);

            $quantity = $this->cart->normalizeQuantity($line['quantity'] ?? 1);
            $basePrice = (float) $product->base_price;

            try {
                $lineTotal = $this->pricing->lineTotal($basePrice, $deltas, $quantity);
            } catch (InvalidArgumentException) {
                $this->rejectChangedOptions($product);
            }

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
    private function currentOptions(array $selectedOptions, Product $product): array
    {
        $groups = $product->optionGroups->keyBy('id');
        $values = $groups
            ->flatMap(fn ($group) => $group->optionValues)
            ->keyBy('id');
        $options = [];
        $deltas = [];
        $selectedByGroup = [];
        $hasLegacyOptions = false;

        foreach ($selectedOptions as $option) {
            if (! array_key_exists('option_value_id', $option)) {
                // Cart line built before option ids were stored: keep its snapshot.
                $hasLegacyOptions = true;
                $delta = (float) ($option['price_delta'] ?? 0);
                $options[] = $option;
                $deltas[] = $delta;

                continue;
            }

            $value = $values->get((int) $option['option_value_id']);
            $group = $value ? $groups->get($value->option_group_id) : null;

            if (! $value || ! $group) {
                $this->rejectChangedOptions($product);
            }

            $delta = (float) $value->price_delta;
            $options[] = [
                'option_value_id' => $value->id,
                'group' => $group->name,
                'value' => $value->name,
                'price_delta' => $delta,
            ];
            $deltas[] = $delta;
            $selectedByGroup[$group->id][] = $value->id;
        }

        // A tampered/stale payload could submit "Σκέτος + Στέβια": canonicalize
        // it down to just the plain coffee rather than trust the pair as-is.
        $options = OptionsPresenter::canonicalize($options);
        $deltas = array_column($options, 'price_delta');

        foreach ($groups as $group) {
            $selectedCount = count(array_unique($selectedByGroup[$group->id] ?? []));

            if ($group->selection === SelectionType::Single) {
                if ($selectedCount > 1 || (! $hasLegacyOptions && $group->is_required && $selectedCount === 0)) {
                    $this->rejectChangedOptions($product);
                }

                continue;
            }

            $minimum = $group->is_required ? (int) ($group->min_select ?? 1) : 0;

            if ((! $hasLegacyOptions && $selectedCount < $minimum)
                || ($group->max_select !== null && $selectedCount > (int) $group->max_select)) {
                $this->rejectChangedOptions($product);
            }
        }

        return [$options, $deltas];
    }

    private function rejectChangedOptions(Product $product): never
    {
        throw ValidationException::withMessages([
            'cart' => 'Οι επιλογές για '.$product->name
                .' άλλαξαν. Αφαιρέστε το προϊόν και προσθέστε το ξανά.',
        ]);
    }
}
