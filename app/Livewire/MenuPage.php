<?php

namespace App\Livewire;

use App\Enums\OrderStatus;
use App\Enums\SelectionType;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use App\Services\PricingService;
use Illuminate\Support\Str;
use Livewire\Component;

class MenuPage extends Component
{
    private const LATEST_PUBLIC_ORDER_SESSION_KEY = 'latest_public_order_route_key';

    private const MAX_ITEM_NOTES = 255;

    public array $cart = [];

    public ?string $latestTrackableOrderToken = null;

    // Modal state
    public ?int $openProductId = null;

    public array $selectedOptions = [];

    public int $quantity = 1;

    public string $itemNotes = '';

    // Coupon state. The applied code itself lives in the cart session value,
    // not here: this is only what the customer is currently typing.
    public string $couponInput = '';

    public ?string $couponError = null;

    public function mount(): void
    {
        $this->cart = app(CartService::class)->items();

        $latestOrderRouteKey = session(self::LATEST_PUBLIC_ORDER_SESSION_KEY);

        if (! is_string($latestOrderRouteKey) || $latestOrderRouteKey === '') {
            session()->forget(self::LATEST_PUBLIC_ORDER_SESSION_KEY);

            return;
        }

        $latestOrder = Order::query()->where('public_token', $latestOrderRouteKey)->first();

        if (! $latestOrder || in_array($latestOrder->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
            session()->forget(self::LATEST_PUBLIC_ORDER_SESSION_KEY);

            return;
        }

        $this->latestTrackableOrderToken = $latestOrder->getRouteKey();
    }

    public function openProduct(int $id): void
    {
        $product = $this->menuProduct($id, withOptions: true);

        $this->openProductId = $id;
        $this->quantity = 1;
        $this->itemNotes = '';
        $this->selectedOptions = [];

        foreach ($product->optionGroups as $group) {
            if ($group->selection === SelectionType::Single) {
                $default = $group->optionValues->firstWhere('is_default', true)
                    ?? $group->optionValues->first();
                if ($default) {
                    $this->selectedOptions[$group->id] = $default->id;
                }
            } else {
                $defaults = $group->optionValues->where('is_default', true)->pluck('id')->toArray();
                $this->selectedOptions[$group->id] = $defaults;
            }
        }
    }

    public function closeModal(): void
    {
        $this->openProductId = null;
    }

    public function addDirectly(int $id): void
    {
        $product = $this->menuProduct($id);

        $line = [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => (float) $product->base_price,
            'selected_options' => [],
            'quantity' => 1,
            'line_total' => (float) $product->base_price,
            'notes' => '',
        ];

        app(CartService::class)->add($line);
        $this->cart = app(CartService::class)->items();
        $this->dispatch('cart-updated', cart: $this->cart, count: count($this->cart), productId: $product->id);
    }

    public function addToCart(): void
    {
        $product = $this->menuProduct((int) $this->openProductId, withOptions: true);

        $snapshotOptions = [];
        $deltas = [];

        foreach ($product->optionGroups as $group) {
            $selected = $this->selectedOptions[$group->id] ?? null;

            if ($group->selection === SelectionType::Single) {
                if ($group->is_required && ! $selected) {
                    $this->addError('options', 'Παρακαλώ επιλέξτε για: '.$group->name);

                    return;
                }
                if ($selected) {
                    $value = $group->optionValues->firstWhere('id', (int) $selected);
                    if ($value) {
                        $snapshotOptions[] = $this->optionSnapshot($group->name, $value);
                        $deltas[] = (float) $value->price_delta;
                    }
                }
            } else {
                // Duplicate ids would let the same option be counted many times.
                $selectedIds = array_unique(array_map('intval', (array) ($selected ?? [])));

                if ($group->is_required && count($selectedIds) < ($group->min_select ?? 1)) {
                    $this->addError('options', 'Παρακαλώ επιλέξτε για: '.$group->name);

                    return;
                }

                if ($group->max_select !== null && count($selectedIds) > $group->max_select) {
                    $this->addError('options', 'Επιλέξτε έως '.$group->max_select.' για: '.$group->name);

                    return;
                }

                foreach ($selectedIds as $valueId) {
                    $value = $group->optionValues->firstWhere('id', $valueId);
                    if ($value) {
                        $snapshotOptions[] = $this->optionSnapshot($group->name, $value);
                        $deltas[] = (float) $value->price_delta;
                    }
                }
            }
        }

        $quantity = app(CartService::class)->normalizeQuantity($this->quantity);
        $this->quantity = $quantity;

        $pricing = app(PricingService::class);
        $lineTotal = $pricing->lineTotal((float) $product->base_price, $deltas, $quantity);

        $line = [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => (float) $product->base_price,
            'selected_options' => $snapshotOptions,
            'quantity' => $quantity,
            'line_total' => $lineTotal,
            'notes' => Str::limit(trim($this->itemNotes), self::MAX_ITEM_NOTES, ''),
        ];

        app(CartService::class)->add($line);
        $this->cart = app(CartService::class)->items();
        $this->dispatch('cart-updated', cart: $this->cart, count: count($this->cart), productId: $product->id);
        $this->openProductId = null;
    }

    public function updateQty(int $index, int $qty): void
    {
        if ($qty < 1) {
            $this->removeFromCart($index);

            return;
        }
        app(CartService::class)->update($index, $qty);
        $this->cart = app(CartService::class)->items();
        $this->dispatch('cart-updated', cart: $this->cart, count: count($this->cart));
    }

    public function incrementQty(int $index): void
    {
        $this->adjustQty($index, 1);
    }

    public function decrementQty(int $index): void
    {
        $this->adjustQty($index, -1);
    }

    public function removeFromCart(int $index): void
    {
        app(CartService::class)->remove($index);
        $this->cart = app(CartService::class)->items();
        $this->dispatch('cart-updated', cart: $this->cart, count: count($this->cart));
    }

    private function adjustQty(int $index, int $delta): void
    {
        app(CartService::class)->adjustQuantity($index, $delta);
        $this->cart = app(CartService::class)->items();
        $this->dispatch('cart-updated', cart: $this->cart, count: count($this->cart));
    }

    /**
     * A rejected code leaves the cart exactly as it was — including any coupon
     * already applied. The customer is told why, in one line.
     */
    public function applyCoupon(): void
    {
        $this->couponError = null;

        $cart = app(CartService::class);
        $code = trim($this->couponInput);

        if ($code === '') {
            $this->couponError = 'Γράψτε έναν κωδικό κουπονιού.';

            return;
        }

        $coupon = Coupon::findByCode($code);

        if (! $coupon) {
            $this->couponError = 'Άγνωστος κωδικός κουπονιού.';

            return;
        }

        $reason = $coupon->rejectionReason($cart->subtotal());

        if ($reason !== null) {
            $this->couponError = $reason;

            return;
        }

        $cart->applyCoupon($coupon);
        $this->couponInput = '';
    }

    public function removeCoupon(): void
    {
        app(CartService::class)->removeCoupon();
        $this->couponError = null;
        $this->couponInput = '';
    }

    /**
     * Only products that are actually on the menu can enter a cart: available,
     * and in an active category. Ids come from the browser.
     */
    private function menuProduct(int $id, bool $withOptions = false): Product
    {
        return Product::query()
            ->available()
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->when($withOptions, fn ($q) => $q->with('optionGroups.optionValues'))
            ->findOrFail($id);
    }

    private function optionSnapshot(string $groupName, OptionValue $value): array
    {
        return [
            'option_value_id' => $value->id,
            'group' => $groupName,
            'value' => $value->name,
            'price_delta' => (float) $value->price_delta,
        ];
    }

    public function render()
    {
        $categories = Category::with([
            'products' => fn ($q) => $q->available()->orderBy('sort_order'),
        ])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($cat) => $cat->products->isNotEmpty());

        $openProduct = $this->openProductId
            ? Product::query()
                ->available()
                ->whereHas('category', fn ($q) => $q->where('is_active', true))
                ->with('optionGroups.optionValues')
                ->find($this->openProductId)
            : null;

        $cart = app(CartService::class);
        $totals = $cart->totals();
        $appliedCoupon = $cart->couponCode();

        // A coupon applied earlier can stop qualifying when the basket shrinks.
        // The discount is already gone from $totals; say why rather than let it
        // disappear silently.
        $couponNotice = $appliedCoupon && $totals['discount'] <= 0
            ? ($cart->coupon()?->rejectionReason($totals['subtotal'])
                ?? 'Ο κωδικός δεν είναι πλέον διαθέσιμος.')
            : null;

        return view('livewire.menu-page', compact(
            'categories', 'openProduct', 'totals', 'appliedCoupon', 'couponNotice',
        ))->layout('layouts.app');
    }
}
