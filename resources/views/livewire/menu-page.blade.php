@php
    use App\Enums\SelectionType;

    /**
     * Stable identity per cart line, so Livewire morphs a line in place instead
     * of matching by position. Quantity is deliberately excluded: changing it
     * must update the same row, not mint a new one. Two lines can legitimately
     * be identical (add the same product twice), so an occurrence counter keeps
     * the keys unique without falling back to the array index.
     */
    $cartLineKeys = [];
    $cartLineSeen = [];

    foreach ($cart as $cartIndex => $cartLine) {
        $signature = md5(json_encode([
            $cartLine['product_id'] ?? null,
            array_column($cartLine['selected_options'] ?? [], 'option_value_id'),
            $cartLine['notes'] ?? '',
        ]));

        $cartLineSeen[$signature] = ($cartLineSeen[$signature] ?? 0) + 1;
        $cartLineKeys[$cartIndex] = $signature.'-'.$cartLineSeen[$signature];
    }

    $cartCount = array_sum(array_column($cart, 'quantity'));
    $cartCountLabel = $cartCount.' '.($cartCount === 1 ? 'προϊόν' : 'προϊόντα');
@endphp
<div
    x-data="menu(@js($categories->first()?->slug ?? ''))"
    x-init="initScrollSpy()"
    x-on:cart-updated.window="syncCart($event.detail)"
    x-on:scroll.window="onScroll()"
    class="min-h-dvh bg-white overflow-x-clip"
>
@if($unavailableProductNotice)
    <div class="mx-auto max-w-7xl px-4 pt-4 lg:px-8" data-unavailable-product-notice>
        <p
            class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-950"
            role="status"
        >{{ $unavailableProductNotice }}</p>
    </div>
@endif

@if(! $isAcceptingOrders)
    <section data-store-closed-banner class="border-b border-amber-300 bg-amber-50 px-4 py-3 text-center text-amber-950">
        <p class="font-display text-sm font-extrabold">
            {{ 'Κλειστά'.($nextOpeningText ? ' — '.$nextOpeningText : '') }}
        </p>
        @if($closedMessage)
            <p class="mt-1 text-xs font-medium">{{ $closedMessage }}</p>
        @endif
    </section>
@endif

{{-- ══ HERO ══
     Flat espresso band with the wordmark, once, before the first product.
     Below xl this is the only brand element; the rail takes over from xl. --}}
<section class="hero-gradient text-white xl:hidden">
    <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-5 lg:px-8">
        <div class="flex min-w-0 items-center gap-3">
            @if($storeSettings->logoUrl())
                <img src="{{ $storeSettings->logoUrl() }}" alt="{{ $storeSettings->displayName() }}" class="size-11 shrink-0 rounded-lg object-contain" />
            @endif
            <div class="min-w-0">
                <h1 class="font-display text-lg font-extrabold tracking-tight">{{ $storeSettings->displayName() }}</h1>
                <p class="mt-2 text-[13px] font-medium text-white/75">Παραγγελία online · delivery και take away</p>
            </div>
        </div>

        @if($latestTrackableOrderToken)
            <a
                href="{{ route('order.track', $latestTrackableOrderToken) }}"
                class="flex min-h-11 shrink-0 items-center rounded-xl border border-white/25 px-3.5 text-[13px] font-semibold transition hover:bg-white/10"
            >
                Παρακολούθηση παραγγελίας →
            </a>
        @endif
    </div>
</section>

{{-- ══ STICKY CATEGORY BAR ══
     Full width, outside the content column. Pills are 44px targets; the row
     scrolls with snap and the last pill peeks so it reads as scrollable. --}}
<header
    x-bind:class="stickyHeaderClass()"
    class="sticky top-0 z-30 border-b border-[var(--hairline)] bg-white/95 backdrop-blur xl:hidden"
>
    <div class="mx-auto max-w-7xl lg:px-8">
        <div class="flex items-center gap-2 py-2 pl-4 pr-2 lg:px-0">
                <span
                    x-cloak
                    x-show="scrolled"
                    class="font-display mr-1 hidden shrink-0 self-center text-sm font-extrabold tracking-tight lg:block"
                >{{ $storeSettings->displayName() }}</span>

                <nav class="scrollbar-hide -ml-4 flex min-w-0 flex-1 gap-1.5 overflow-x-auto py-1 pl-4 pr-6 lg:ml-0 lg:pl-0"
                     style="scroll-snap-type: x mandatory;">
                    @foreach($categories as $category)
                        <a
                            href="#cat-{{ $category->slug }}"
                            data-pill="{{ $category->slug }}"
                            x-bind:class="pillClass(@js($category->slug))"
                            x-on:click.prevent="scrollToCategory(@js($category->slug))"
                            class="flex min-h-11 shrink-0 items-center whitespace-nowrap rounded-full px-4 text-sm font-semibold transition-colors"
                            style="scroll-snap-align: start;"
                        >{{ $category->name }}</a>
                    @endforeach
                </nav>

                {{-- Cart icon (mobile backup access) --}}
                @if($cart)
                    <button type="button" x-on:click="openCart()" aria-label="Άνοιγμα καλαθιού" class="relative grid size-11 shrink-0 place-items-center rounded-xl text-[var(--ink)] lg:hidden">
                        <svg class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13l-1.4 7h12.8"/>
                        </svg>
                        <span class="price absolute right-0.5 top-0.5 flex size-5 items-center justify-center rounded-full text-[11px] font-bold leading-none text-white" style="background: var(--accent)">{{ $cartCount }}</span>
                    </button>
                @endif
            </div>
    </div>
</header>

{{-- ══ MAIN SHELL: content + cart sidebar from lg, plus the category rail from xl ══ --}}
<div class="mx-auto max-w-7xl px-4 lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start lg:gap-8 lg:px-8 xl:max-w-[1440px] xl:grid-cols-[240px_minmax(0,1fr)_340px] 2xl:max-w-[1480px] 2xl:grid-cols-[256px_minmax(0,1fr)_360px] 2xl:gap-10">

    {{-- Category rail: brand, categories, nothing else. --}}
    <aside class="hidden xl:sticky xl:top-6 xl:block xl:max-h-[calc(100vh-3rem)] xl:overflow-y-auto">
        <div class="rounded-2xl border border-[var(--hairline)] bg-white">
            <h1 class="hero-gradient flex items-center rounded-t-2xl px-4 py-4 font-display text-lg font-extrabold tracking-tight">{{ config('app.name') }}</h1>

            <nav class="p-2" aria-label="Κατηγορίες μενού">
                @foreach($categories as $category)
                    <a
                        href="#cat-{{ $category->slug }}"
                        x-bind:class="pillClass(@js($category->slug))"
                        x-on:click.prevent="scrollToCategory(@js($category->slug))"
                        class="flex min-h-11 items-center justify-between gap-3 rounded-xl px-3 text-sm font-semibold transition-colors"
                    >
                        <span class="truncate">{{ $category->name }}</span>
                        <span class="price text-xs font-medium opacity-65">{{ $category->products->count() }}</span>
                    </a>
                @endforeach
            </nav>

            @if($latestTrackableOrderToken)
                <div class="border-t border-[var(--hairline)] p-3">
                    <a href="{{ route('order.track', $latestTrackableOrderToken) }}" class="flex min-h-11 items-center justify-center rounded-xl border border-[var(--hairline)] px-3 text-sm font-semibold transition hover:border-[var(--muted)]">
                        Παρακολούθηση παραγγελίας →
                    </a>
                </div>
            @endif
        </div>
    </aside>

    <div class="min-w-0">

        {{-- Content header, rail widths only: one line, no marketing copy. --}}
        <div class="hidden items-baseline justify-between border-b border-[var(--hairline)] py-6 xl:flex">
            <h2 class="font-display text-2xl font-extrabold tracking-tight">Μενού</h2>
            <p class="text-sm text-[var(--muted)]">{{ $categories->count() }} κατηγορίες</p>
        </div>

        {{-- ── PRODUCT LIST / GRID ── --}}
        <main class="space-y-8 py-4 pb-32 sm:py-6 lg:space-y-10 lg:pb-12 xl:py-8">
            @foreach($categories as $category)
                <section id="cat-{{ $category->slug }}" data-slug="{{ $category->slug }}" class="scroll-mt-20 xl:scroll-mt-6">
                    <div class="mb-2 flex items-baseline gap-2 sm:mb-3">
                        <h2 class="font-display text-xl font-extrabold tracking-tight lg:text-[1.35rem]">
                            {{ $category->name }}
                        </h2>
                        <span class="price text-sm text-[var(--muted)]">{{ $category->products->count() }}</span>
                    </div>
                    <div data-product-grid class="grid grid-cols-1 gap-0 sm:grid-cols-2 sm:gap-3">
                        @foreach($category->products as $product)
                            @include('livewire.partials.product-card', ['product' => $product, 'category' => $category])
                        @endforeach
                    </div>
                </section>
            @endforeach
        </main>
    </div>

    {{-- ══ CART SIDEBAR (lg and up) ══ --}}
    <aside class="hidden shrink-0 lg:sticky lg:top-6 lg:block lg:w-full">
        <div class="flex flex-col overflow-hidden rounded-2xl border border-[var(--hairline)] bg-white" style="max-height: calc(100vh - 3rem);">
            <div class="flex shrink-0 items-baseline justify-between border-b border-[var(--hairline)] px-4 py-3.5">
                <h2 class="font-display text-base font-extrabold tracking-tight">Η παραγγελία σου</h2>
                @if($cart)
                    <span class="price text-[13px] text-[var(--muted)]">{{ $cartCountLabel }}</span>
                @endif
            </div>

            <div class="flex-1 overflow-y-auto px-4 py-1">
                @forelse($cart as $index => $line)
                    @include('livewire.partials.cart-line', [
                        'line' => $line,
                        'index' => $index,
                        'key' => $cartLineKeys[$index],
                        'scope' => 'desktop',
                        'compact' => true,
                    ])
                @empty
                    <div class="px-4 py-10 text-center">
                        <p class="text-sm font-semibold">Άδειο καλάθι</p>
                        <p class="mt-1 text-[13px] leading-relaxed text-[var(--muted)]">Διάλεξε κάτι από το μενού και θα εμφανιστεί εδώ.</p>
                    </div>
                @endforelse
            </div>

            @if($cart)
            <div class="shrink-0 border-t border-[var(--hairline)] bg-[var(--sunken)] px-4 py-4">
                @include('livewire.partials.cart-summary', ['scope' => 'desktop'])
                <a
                    href="{{ $isAcceptingOrders ? '/checkout' : '#' }}"
                    aria-disabled="{{ $isAcceptingOrders ? 'false' : 'true' }}"
                    class="flex min-h-12 w-full items-center justify-center rounded-xl px-4 text-center text-[15px] font-bold text-white transition active:scale-95 hover:brightness-95 {{ $isAcceptingOrders ? '' : 'pointer-events-none opacity-60' }}"
                    style="background: var(--accent);"
                >{{ $isAcceptingOrders ? 'Ολοκλήρωση παραγγελίας' : 'Το κατάστημα είναι κλειστό' }}</a>
            </div>
            @endif
        </div>
    </aside>
</div>

{{-- ══ MOBILE STICKY BOTTOM CART BAR ══ --}}
@if($cart)
<div
    class="fixed bottom-0 left-0 right-0 z-40 px-4 pb-4 lg:hidden"
    style="padding-bottom: max(1rem, env(safe-area-inset-bottom));"
>
    <button
        type="button"
        x-on:click="openCart()"
        class="flex min-h-14 w-full items-center gap-3 rounded-xl px-4 text-white shadow-[0_8px_24px_-8px_rgb(28_25_23_/_0.45)] transition active:scale-[.98]"
        style="background: var(--accent);"
    >
        <span class="price grid size-8 shrink-0 place-items-center rounded-lg bg-white/20 text-sm font-bold">{{ $cartCount }}</span>
        <span class="text-[15px] font-bold">Ολοκλήρωση</span>
        <span class="price font-display ml-auto text-lg font-extrabold">{{ number_format($totals['total'], 2, ',', '.') }} €</span>
    </button>
</div>
@endif

{{-- ══ MOBILE CART BOTTOM SHEET ══ --}}
{{-- Backdrop --}}
<div
    x-show="cartOpen"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-40 bg-black/50 lg:hidden"
    x-on:click="closeCart()"
    x-cloak
></div>

{{-- Sheet --}}
<div
    x-show="cartOpen"
    x-transition:enter="transition ease-out duration-250"
    x-transition:enter-start="translate-y-full"
    x-transition:enter-end="translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="translate-y-0"
    x-transition:leave-end="translate-y-full"
    class="fixed bottom-0 left-0 right-0 z-50 mx-auto flex max-w-lg flex-col rounded-t-2xl bg-white shadow-2xl lg:hidden"
    style="max-height: 85vh; padding-bottom: env(safe-area-inset-bottom);"
    x-cloak
>
    {{-- Drag handle --}}
    <div class="flex shrink-0 justify-center pb-1 pt-3">
        <div class="h-1 w-10 rounded-full bg-[var(--hairline)]"></div>
    </div>

    {{-- Title row --}}
    <div class="flex shrink-0 items-center justify-between border-b border-[var(--hairline)] py-2 pl-4 pr-2">
        <h2 class="font-display text-lg font-extrabold tracking-tight">Η παραγγελία σου</h2>
        <button type="button" x-on:click="closeCart()" class="min-h-11 px-3 text-sm font-semibold text-[var(--ink-soft)]">Κλείσιμο</button>
    </div>

    {{-- Items --}}
    <div class="flex-1 overflow-y-auto px-4 py-1">
        @forelse($cart as $index => $line)
            @include('livewire.partials.cart-line', [
                'line' => $line,
                'index' => $index,
                'key' => $cartLineKeys[$index],
                'scope' => 'mobile',
            ])
        @empty
            <div class="px-4 py-12 text-center">
                <p class="text-sm font-semibold">Άδειο καλάθι</p>
                <p class="mt-1 text-[13px] leading-relaxed text-[var(--muted)]">Διάλεξε κάτι από το μενού και θα εμφανιστεί εδώ.</p>
            </div>
        @endforelse
    </div>

    {{-- Footer: subtotal + CTA --}}
    @if($cart)
    <div class="shrink-0 border-t border-[var(--hairline)] bg-[var(--sunken)] px-4 pb-4 pt-4">
        @include('livewire.partials.cart-summary', ['scope' => 'mobile'])
        <a
            href="{{ $isAcceptingOrders ? '/checkout' : '#' }}"
            aria-disabled="{{ $isAcceptingOrders ? 'false' : 'true' }}"
            class="flex min-h-14 w-full items-center justify-center rounded-xl px-4 text-center text-base font-bold text-white transition active:scale-[.98] {{ $isAcceptingOrders ? '' : 'pointer-events-none opacity-60' }}"
            style="background: var(--accent);"
        >{{ $isAcceptingOrders ? 'Ολοκλήρωση παραγγελίας' : 'Το κατάστημα είναι κλειστό' }}</a>
    </div>
    @endif
</div>

{{-- ══ PRODUCT MODAL (bottom sheet on mobile, centered dialog on desktop) ══ --}}
@if($openProduct)
<div
    data-product-customization-modal
    x-on:touchmove.stop
    class="fixed inset-0 z-50 flex items-end lg:items-center justify-center"
    x-data="{
        selectedOptions: @js($selectedOptions),
        quantity: {{ $quantity }},
        basePrice: {{ (float) $openProduct->base_price }},
        groups: @js($openProduct->optionGroups->map(fn($g) => [
            'id'          => $g->id,
            'name'        => $g->name,
            'selection'   => $g->selection->value,
            'is_required' => $g->is_required,
            'min_select'  => $g->min_select,
            'max_select'  => $g->max_select,
            'values'      => $g->optionValues->map(fn($v) => [
                'id'          => $v->id,
                'name'        => $v->name,
                'price_delta' => (float) $v->price_delta,
            ])->values()->all(),
        ])->values()->all()),

        // Ζάχαρη (sweetness) / Γλυκαντικό (sweetener) is the one coffee-specific
        // pair with a dependency: matched by name, since group/value ids are a
        // database detail. Σκέτος (plain) makes any sweetener choice moot.
        sweetnessGroupName: 'Ζάχαρη',
        sweetenerGroupName: 'Γλυκαντικό',
        plainValueName: 'Σκέτος',
        defaultSweetenerValueName: 'Ζάχαρη',

        get sweetenerGroup() {
            return this.groups.find(g => g.name === this.sweetenerGroupName) || null;
        },
        get isPlain() {
            const sweetnessGroup = this.groups.find(g => g.name === this.sweetnessGroupName);
            if (!sweetnessGroup) return false;
            const selected = sweetnessGroup.values.find(v => v.id == this.selectedOptions[sweetnessGroup.id]);
            return selected ? selected.name === this.plainValueName : false;
        },
        init() {
            // Keeps selectedOptions consistent as the sweetness pick changes:
            // going plain drops any sweetener choice so it can never reach the
            // cart snapshot; leaving plain restores a safe default sweetener.
            this.$watch('isPlain', (isPlain) => {
                const group = this.sweetenerGroup;
                if (!group) return;
                if (isPlain) {
                    delete this.selectedOptions[group.id];
                } else if (!this.selectedOptions[group.id]) {
                    const fallback = group.values.find(v => v.name === this.defaultSweetenerValueName) || group.values[0];
                    if (fallback) this.selectedOptions[group.id] = fallback.id;
                }
            });
        },

        get totalDelta() {
            let d = 0;
            for (const g of this.groups) {
                if (g.selection === 'single') {
                    const val = g.values.find(v => v.id == this.selectedOptions[g.id]);
                    if (val) d += val.price_delta;
                } else {
                    for (const id of (this.selectedOptions[g.id] || [])) {
                        const val = g.values.find(v => v.id == id);
                        if (val) d += val.price_delta;
                    }
                }
            }
            return d;
        },
        get lineTotal() { return (this.basePrice + this.totalDelta) * this.quantity; },
        get canAdd() {
            for (const g of this.groups) {
                if (!g.is_required) continue;
                if (g.selection === 'single' && !this.selectedOptions[g.id]) return false;
                if (g.selection !== 'single' && (this.selectedOptions[g.id] || []).length < (g.min_select || 1)) return false;
            }
            return true;
        },
        toggleMulti(gid, vid) {
            if (!this.selectedOptions[gid]) this.selectedOptions[gid] = [];
            const i = this.selectedOptions[gid].indexOf(vid);
            i === -1 ? this.selectedOptions[gid].push(vid) : this.selectedOptions[gid].splice(i, 1);
        }
    }"
>
    {{-- Backdrop --}}
    <div class="absolute inset-0 bg-black/50" wire:click="closeModal"></div>

    {{-- Sheet --}}
    <div class="relative z-10 flex w-full min-h-0 max-h-[calc(100dvh-env(safe-area-inset-top))] flex-col rounded-t-2xl bg-white shadow-2xl lg:mx-4 lg:max-h-[88vh] lg:max-w-lg lg:rounded-2xl"
        style="padding-bottom: env(safe-area-inset-bottom);">

        {{-- Drag handle --}}
        <div class="flex shrink-0 justify-center pb-2 pt-3 lg:hidden">
            <div class="h-1 w-10 rounded-full bg-[var(--hairline)]"></div>
        </div>

        {{-- Product photo + name + close --}}
        <div class="flex shrink-0 items-center gap-3 border-b border-[var(--hairline)] px-4 pb-3 lg:pt-4">
            @if($openProduct->image_url)
                <img src="{{ $openProduct->image_url }}" alt="" loading="lazy" decoding="async" onerror="this.hidden = true" class="size-14 shrink-0 rounded-xl bg-[var(--sunken)] object-cover">
            @endif
            <div class="min-w-0 flex-1">
                <h3 class="font-display text-lg font-extrabold leading-tight tracking-tight">{{ $openProduct->name }}</h3>
                <p class="price mt-0.5 text-sm font-bold">
                    {{ number_format($openProduct->base_price, 2, ',', '.') }} €
                </p>
            </div>
            <button
                type="button"
                wire:click="closeModal"
                aria-label="Κλείσιμο"
                class="grid size-11 shrink-0 place-items-center rounded-xl border border-[var(--hairline)] text-[var(--ink-soft)] transition hover:border-[var(--muted)]"
            >
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l10 10M15 5L5 15"/></svg>
            </button>
        </div>

        {{-- Scrollable options --}}
        <div
            x-on:touchmove.stop
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-4 space-y-6"
            style="-webkit-overflow-scrolling: touch; touch-action: pan-y;"
        >
            @foreach($openProduct->optionGroups as $group)
                {{-- Γλυκαντικό only makes sense once the coffee isn't plain;
                     isPlain is the same name-driven check that also cleans
                     selectedOptions when the sweetness pick changes. --}}
                <div @if($group->name === 'Γλυκαντικό') x-show="!isPlain" @endif>
                    {{-- The limits are stated up front rather than left for the
                         validation error to explain after the fact. --}}
                    <div class="mb-2.5 flex items-baseline gap-2">
                        <h4 class="font-display text-base font-extrabold tracking-tight">{{ $group->name }}</h4>
                        @if($group->is_required)
                            <span class="text-[10.5px] font-semibold uppercase tracking-wide text-[var(--ink-soft)]">Υποχρεωτικό</span>
                        @endif
                        @if($group->selection === SelectionType::Multi && ($group->min_select || $group->max_select))
                            <span class="ml-auto shrink-0 text-[11.5px] font-medium text-[var(--muted)]">
                                @if($group->min_select > 1)Τουλάχιστον {{ $group->min_select }}@endif
                                @if($group->min_select > 1 && $group->max_select) · @endif
                                @if($group->max_select)Έως {{ $group->max_select }}@endif
                            </span>
                        @endif
                    </div>

                    @if($group->selection === SelectionType::Single)
                        {{-- Selected state is the same one the category pills
                             use — accent border, accent-light fill, accent-text
                             label — driven by peer-checked off a visually
                             hidden but still real radio, so the surface is the
                             indicator and assistive tech keeps a native
                             control to announce. --}}
                        <div class="space-y-2">
                            @foreach($group->optionValues as $value)
                                <label class="block cursor-pointer">
                                    <input type="radio"
                                        name="g{{ $group->id }}"
                                        value="{{ $value->id }}"
                                        x-model="selectedOptions[{{ $group->id }}]"
                                        class="peer sr-only">
                                    <span class="flex min-h-12 items-center gap-3 rounded-xl border border-[var(--hairline)] bg-white px-4 py-2.5 transition hover:border-[var(--muted)] peer-checked:border-[var(--accent)] peer-checked:bg-[var(--accent-light)] peer-checked:text-[var(--accent-text)] peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--accent)]">
                                        <span class="flex-1 text-[15px] font-medium leading-snug">{{ $value->name }}</span>
                                        @if($value->price_delta > 0)
                                            <span class="price shrink-0 text-[13.5px] font-bold">
                                                +{{ number_format($value->price_delta, 2, ',', '.') }} €
                                            </span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @else
                        {{-- Identical surface to the single-select above; only
                             the input type differs. x-bind:checked sets the
                             checked property, which is what :checked reflects,
                             so peer-checked tracks Alpine's array without any
                             class expression in the attribute. --}}
                        <div class="space-y-2">
                            @foreach($group->optionValues as $value)
                                <label class="block cursor-pointer">
                                    <input type="checkbox"
                                        x-bind:checked="(selectedOptions[{{ $group->id }}] || []).includes({{ $value->id }})"
                                        x-on:change="toggleMulti({{ $group->id }}, {{ $value->id }})"
                                        class="peer sr-only">
                                    <span class="flex min-h-12 items-center gap-3 rounded-xl border border-[var(--hairline)] bg-white px-4 py-2.5 transition hover:border-[var(--muted)] peer-checked:border-[var(--accent)] peer-checked:bg-[var(--accent-light)] peer-checked:text-[var(--accent-text)] peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--accent)]">
                                        <span class="flex-1 text-[15px] font-medium leading-snug">{{ $value->name }}</span>
                                        @if($value->price_delta > 0)
                                            <span class="price shrink-0 text-[13.5px] font-bold">
                                                +{{ number_format($value->price_delta, 2, ',', '.') }} €
                                            </span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- Notes --}}
            <div>
                <label for="item-notes" class="mb-2 block font-display text-base font-extrabold tracking-tight">Σημειώσεις</label>
                <input type="text"
                    id="item-notes"
                    wire:model="itemNotes"
                    placeholder="π.χ. χωρίς ζάχαρη"
                    class="min-h-12 w-full rounded-xl border border-[var(--hairline)] bg-white px-4 text-[15px] transition placeholder:text-[var(--muted)] focus:border-[var(--accent)] focus:outline-none">
            </div>
        </div>

        {{-- Sticky footer: qty + add button. The running total lives inside the
             button so the customer never has to do the arithmetic before
             committing to it. --}}
        <div class="shrink-0 border-t border-[var(--hairline)] bg-white px-4 pb-4 pt-3">
            @error('options')
                <p class="mb-2.5 rounded-lg bg-red-50 px-3 py-2 text-[13px] font-medium text-red-700">{{ $message }}</p>
            @enderror

            <div class="flex items-center gap-3">
                {{-- Qty --}}
                <div class="flex shrink-0 items-center gap-1.5">
                    <button type="button"
                        x-on:click="if (quantity > 1) quantity--"
                        aria-label="Μείωση ποσότητας"
                        class="grid size-12 place-items-center rounded-xl border border-[var(--hairline)] bg-white text-[var(--ink)] transition hover:border-[var(--muted)] active:scale-95">
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M5 10h10"/></svg>
                    </button>
                    <span class="price w-8 text-center text-base font-bold" x-text="quantity"></span>
                    <button type="button"
                        x-on:click="quantity++"
                        aria-label="Αύξηση ποσότητας"
                        class="grid size-12 place-items-center rounded-xl border border-[var(--hairline)] bg-white text-[var(--ink)] transition hover:border-[var(--muted)] active:scale-95">
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M10 5v10M5 10h10"/></svg>
                    </button>
                </div>
                {{-- CTA --}}
                <button
                    type="button"
                    x-on:click="$wire.set('quantity', quantity); $wire.set('selectedOptions', selectedOptions); $wire.addToCart()"
                    x-bind:disabled="!canAdd"
                    x-bind:class="canAdd ? 'active:scale-95' : 'opacity-40 cursor-not-allowed'"
                    class="flex min-h-12 flex-1 items-center justify-center rounded-xl px-4 text-base font-bold text-white transition"
                    style="background: var(--accent);"
                >
                    Προσθήκη · <span class="price" x-text="lineTotal.toFixed(2).replace('.', ',') + ' €'"></span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>
