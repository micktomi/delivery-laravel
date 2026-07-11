<?php

namespace App\Livewire;

use App\Enums\OrderStatus;
use App\Enums\SelectionType;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\CartService;
use App\Services\PricingService;
use Livewire\Component;

class MenuPage extends Component
{
    private const LATEST_PUBLIC_ORDER_SESSION_KEY = 'latest_public_order_route_key';

    public array $cart = [];

    public ?int $latestTrackableOrderId = null;

    // Modal state
    public ?int $openProductId = null;

    public array $selectedOptions = [];

    public int $quantity = 1;

    public string $itemNotes = '';

    public function mount(): void
    {
        $this->cart = app(CartService::class)->items();

        $latestOrderRouteKey = session(self::LATEST_PUBLIC_ORDER_SESSION_KEY);

        if (! is_numeric($latestOrderRouteKey)) {
            session()->forget(self::LATEST_PUBLIC_ORDER_SESSION_KEY);

            return;
        }

        $latestOrder = Order::query()->find($latestOrderRouteKey);

        if (! $latestOrder || in_array($latestOrder->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
            session()->forget(self::LATEST_PUBLIC_ORDER_SESSION_KEY);

            return;
        }

        $this->latestTrackableOrderId = (int) $latestOrder->getRouteKey();
    }

    public function openProduct(int $id): void
    {
        $product = Product::with('optionGroups.optionValues')->findOrFail($id);

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
        $product = Product::findOrFail($id);

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
        $product = Product::with('optionGroups.optionValues')->findOrFail($this->openProductId);

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
                        $snapshotOptions[] = [
                            'group' => $group->name,
                            'value' => $value->name,
                            'price_delta' => (float) $value->price_delta,
                        ];
                        $deltas[] = (float) $value->price_delta;
                    }
                }
            } else {
                $selectedIds = (array) ($selected ?? []);
                if ($group->is_required && count($selectedIds) < ($group->min_select ?? 1)) {
                    $this->addError('options', 'Παρακαλώ επιλέξτε για: '.$group->name);

                    return;
                }
                foreach ($selectedIds as $valueId) {
                    $value = $group->optionValues->firstWhere('id', (int) $valueId);
                    if ($value) {
                        $snapshotOptions[] = [
                            'group' => $group->name,
                            'value' => $value->name,
                            'price_delta' => (float) $value->price_delta,
                        ];
                        $deltas[] = (float) $value->price_delta;
                    }
                }
            }
        }

        $pricing = app(PricingService::class);
        $lineTotal = $pricing->lineTotal((float) $product->base_price, $deltas, $this->quantity);

        $line = [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => (float) $product->base_price,
            'selected_options' => $snapshotOptions,
            'quantity' => $this->quantity,
            'line_total' => $lineTotal,
            'notes' => $this->itemNotes,
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

    public function removeFromCart(int $index): void
    {
        app(CartService::class)->remove($index);
        $this->cart = app(CartService::class)->items();
        $this->dispatch('cart-updated', cart: $this->cart, count: count($this->cart));
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
            ? Product::with('optionGroups.optionValues')->find($this->openProductId)
            : null;

        return view('livewire.menu-page', compact('categories', 'openProduct'))
            ->layout('layouts.app');
    }
}
