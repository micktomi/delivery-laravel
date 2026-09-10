<?php

namespace App\Livewire;

use App\Enums\OrderStatus;
use App\Enums\SelectionType;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use App\Services\OptionsPresenter;
use App\Services\PricingService;
use App\Support\StoreSchedule;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;

class MenuPage extends Component
{
    private const LATEST_PUBLIC_ORDER_SESSION_KEY = 'latest_public_order_route_key';

    private const MAX_ITEM_NOTES = 255;

    /** Unknown codes allowed from one browser session before the form stops answering. */
    private const MAX_COUPON_ATTEMPTS = 10;

    /** Higher shared-address backstop so replacing the session cannot reset the budget forever. */
    private const MAX_COUPON_ATTEMPTS_PER_IP = 100;

    private const COUPON_ATTEMPT_WINDOW = 600;

    public array $cart = [];

    /** Customer-facing feedback after the existing availability refresh removes cart lines. */
    public ?string $unavailableProductNotice = null;

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
        $cart = app(CartService::class);
        $storedUnavailableProductNotice = session(CartService::UNAVAILABLE_PRODUCT_NOTICE_SESSION_KEY);
        $this->unavailableProductNotice = is_string($storedUnavailableProductNotice)
            ? $storedUnavailableProductNotice
            : null;
        $cartBeforeAvailabilityRefresh = $cart->items();
        $cart->removeUnavailableProducts();
        $this->cart = $cart->items();
        $newUnavailableProductNotice = $this->unavailableProductNotice(
            $cartBeforeAvailabilityRefresh,
            $this->cart,
        );

        if ($newUnavailableProductNotice !== null) {
            $this->unavailableProductNotice = $newUnavailableProductNotice;
            session()->flash(CartService::UNAVAILABLE_PRODUCT_NOTICE_SESSION_KEY, $newUnavailableProductNotice);
        }

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

    /**
     * The CartService remains responsible for the availability rule and the
     * session update. This only turns the already-removed lines into one clear
     * customer-facing message.
     */
    private function unavailableProductNotice(array $before, array $after): ?string
    {
        $remainingProductIds = array_fill_keys(
            array_map(fn (array $line): int => (int) ($line['product_id'] ?? 0), $after),
            true,
        );

        $removedNames = [];

        foreach ($before as $line) {
            if (isset($remainingProductIds[(int) ($line['product_id'] ?? 0)])) {
                continue;
            }

            $name = trim((string) ($line['product_name'] ?? ''));

            if ($name !== '' && ! in_array($name, $removedNames, true)) {
                $removedNames[] = $name;
            }
        }

        if ($removedNames === []) {
            return null;
        }

        if (count($removedNames) === 1) {
            return 'Το προϊόν '.$removedNames[0].' δεν είναι πλέον διαθέσιμο και αφαιρέθηκε από την παραγγελία.';
        }

        $lastName = array_pop($removedNames);

        return 'Τα προϊόντα '.implode(', ', $removedNames).' και '.$lastName
            .' δεν είναι πλέον διαθέσιμα και αφαιρέθηκαν από την παραγγελία.';
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

        $selectedValueIds = $this->flattenSelectedValueIds($this->selectedOptions);

        $snapshotOptions = [];
        $deltas = [];

        foreach ($product->optionGroups as $group) {
            // E.g. an optional add-on group that a "plain" pick elsewhere
            // makes moot: skip it entirely, same as if nothing was ever
            // shown for it — driven by hidden_when_option_value_id, not names.
            if ($group->isHiddenGiven($selectedValueIds)) {
                continue;
            }

            $selected = $this->selectedOptions[$group->id] ?? null;

            if ($group->selection === SelectionType::Single) {
                if ($group->is_required && ! $selected) {
                    $this->addError('options', 'Παρακαλώ επιλέξτε για: '.$group->name);

                    return;
                }
                if ($selected) {
                    $value = $group->optionValues->firstWhere('id', (int) $selected);
                    if ($value) {
                        $snapshotOptions[] = $this->optionSnapshot($group, $value);
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
                        $snapshotOptions[] = $this->optionSnapshot($group, $value);
                        $deltas[] = (float) $value->price_delta;
                    }
                }
            }
        }

        // Defense in depth: even though hidden groups are already skipped
        // above, canonicalize once more in case of a tampered/stale payload.
        $snapshotOptions = OptionsPresenter::canonicalize($snapshotOptions);
        $deltas = array_column($snapshotOptions, 'price_delta');

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

        // Codes are short and handed out at the counter, so this form must not
        // double as a way of discovering them by typing. Only an unknown code
        // counts as a guess: a real code that no longer qualifies belongs to a
        // customer who was given it.
        //
        // The tight budget is per browser session so a whole street behind one
        // carrier NAT does not share it. A looser IP budget prevents replacing
        // the session cookie from resetting the allowance forever. Both values
        // are hashed before they become cache keys.
        $limiterKey = 'coupon-attempts:'.hash('sha256', session()->getId());
        $ipLimiterKey = 'coupon-attempts-ip:'.hash('sha256', (string) request()->ip());

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_COUPON_ATTEMPTS)
            || RateLimiter::tooManyAttempts($ipLimiterKey, self::MAX_COUPON_ATTEMPTS_PER_IP)) {
            $waitSeconds = max(
                RateLimiter::availableIn($limiterKey),
                RateLimiter::availableIn($ipLimiterKey),
            );
            $minutes = max(1, (int) ceil($waitSeconds / 60));

            $this->couponError = 'Πολλές δοκιμές κωδικού. Δοκιμάστε ξανά σε '.$minutes.' λεπτά.';

            return;
        }

        $coupon = Coupon::findByCode($code);

        if (! $coupon) {
            RateLimiter::hit($limiterKey, self::COUPON_ATTEMPT_WINDOW);
            RateLimiter::hit($ipLimiterKey, self::COUPON_ATTEMPT_WINDOW);

            $this->couponError = 'Άγνωστος κωδικός κουπονιού.';

            return;
        }

        $reason = $coupon->rejectionReason($cart->subtotal());

        if ($reason !== null) {
            $this->couponError = $reason;

            return;
        }

        // The counter is deliberately left standing: landing one real code must
        // not wipe the record of the guesses that came before it.
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

    private function optionSnapshot(OptionGroup $group, OptionValue $value): array
    {
        return [
            'option_value_id' => $value->id,
            'option_group_id' => $group->id,
            'group' => $group->name,
            'value' => $value->name,
            'price_delta' => (float) $value->price_delta,
            'is_default_value' => (bool) $value->is_default,
            'hidden_when_option_value_id' => $group->hidden_when_option_value_id,
            'combine_display_with_option_group_id' => $group->combine_display_with_option_group_id,
        ];
    }

    /**
     * Every option value id currently picked anywhere on the product, single
     * or multi-select alike — used to resolve which groups are hidden.
     *
     * @param  array<int, mixed>  $selectedOptions
     * @return array<int, int>
     */
    private function flattenSelectedValueIds(array $selectedOptions): array
    {
        $ids = [];

        foreach ($selectedOptions as $selected) {
            foreach ((array) $selected as $id) {
                if ($id !== null && $id !== '') {
                    $ids[] = (int) $id;
                }
            }
        }

        return $ids;
    }

    public function render()
    {
        $categories = Category::with([
            // Keep unavailable products visible as disabled cards. The add-to-cart
            // lookup below still applies the availability rule server-side.
            'products' => fn ($q) => $q->orderBy('sort_order'),
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
        // Session state is authoritative; never render the client-hydrated copy.
        $this->cart = $cart->items();
        $totals = $cart->totals();
        $appliedCoupon = $cart->couponCode();

        // A coupon applied earlier can stop qualifying when the basket shrinks.
        // The discount is already gone from $totals; say why rather than let it
        // disappear silently.
        $couponNotice = $appliedCoupon && $totals['discount'] <= 0
            ? ($cart->coupon()?->rejectionReason($totals['subtotal'])
                ?? 'Ο κωδικός δεν είναι πλέον διαθέσιμος.')
            : null;
        $storeSchedule = app(StoreSchedule::class);
        $isAcceptingOrders = $storeSchedule->isAcceptingOrders();
        $nextOpeningText = $storeSchedule->nextOpeningText();
        $closedMessage = $storeSchedule->closedMessage();

        return view('livewire.menu-page', compact(
            'categories', 'openProduct', 'totals', 'appliedCoupon', 'couponNotice',
            'isAcceptingOrders', 'nextOpeningText', 'closedMessage',
        ))->layout('layouts.app');
    }
}
