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
    $cartSubtotal = array_sum(array_map(fn ($line) => (float) $line['line_total'], $cart));
    $cartCountLabel = $cartCount.' '.($cartCount === 1 ? 'προϊόν' : 'προϊόντα');
@endphp
<div
    x-data="menu(@js($categories->first()?->slug ?? ''))"
    x-init="initScrollSpy()"
    x-on:cart-updated.window="syncCart($event.detail)"
    x-on:scroll.window="onScroll()"
    class="min-h-screen bg-white overflow-x-clip"
>

{{-- ══ HERO ══
     No photo. This is a takeaway menu: vertical space above the first product
     is time-to-order. The brand sits here once and collapses into the pill bar
     on scroll. --}}
<section class="hero-gradient text-white">
    <div class="mx-auto max-w-7xl px-4 pb-8 pt-7 lg:px-8 lg:pb-11 lg:pt-10">
        <h1 class="font-display text-[1.7rem] font-extrabold leading-none tracking-tight lg:text-[2.6rem]">
            {{ config('app.name') }}
        </h1>
        <p class="mt-2 text-[13px] font-medium text-white/70 lg:text-[15px]">
            Καφές · Sandwich · Αναψυκτικά — delivery &amp; take away
        </p>

        @if($latestTrackableOrderToken)
            <a
                href="{{ route('order.track', $latestTrackableOrderToken) }}"
                class="mt-5 flex max-w-md items-center justify-between rounded-xl bg-white/10 px-3.5 py-2.5 ring-1 ring-white/15 transition hover:bg-white/15"
            >
                <span class="text-[13px] font-medium">Έχεις μια ενεργή παραγγελία</span>
                <span class="text-[13px] font-semibold text-white/80">Παρακολούθηση παραγγελίας →</span>
            </a>
        @endif
    </div>
</section>

{{-- ══ STICKY CATEGORY BAR ══
     Full width, outside the content column: it spans the page like the hero
     above it, rather than stopping at the edge of the menu column.

     Wraps on desktop instead of overflowing — the café adds categories from
     Filament, and a single row breaks silently at eight of them. --}}
<header
    x-bind:class="stickyHeaderClass()"
    class="sticky top-0 z-30 border-b border-[var(--hairline)] bg-white/95 backdrop-blur"
>
    <div class="mx-auto max-w-7xl px-4 lg:px-8">
        <div class="flex items-center gap-2 py-3">
                {{-- Collapsed brand mark, only once the hero has scrolled away. --}}
                <span
                    x-cloak
                    x-show="scrolled"
                    class="font-display mr-1 hidden shrink-0 self-center text-sm font-extrabold tracking-tight lg:block"
                >{{ config('app.name') }}</span>

                <nav class="scrollbar-hide flex min-w-0 flex-1 gap-2 overflow-x-auto lg:flex-wrap lg:overflow-visible"
                     style="scroll-snap-type: x mandatory;">
                    @foreach($categories as $category)
                        <a
                            href="#cat-{{ $category->slug }}"
                            data-pill="{{ $category->slug }}"
                            x-bind:class="pillClass(@js($category->slug))"
                            x-on:click.prevent="scrollToCategory(@js($category->slug))"
                            class="shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-[13px] font-semibold transition-colors"
                            style="scroll-snap-align: start;"
                        >{{ $category->name }}</a>
                    @endforeach
                </nav>

                {{-- Cart icon (mobile backup access) --}}
                @if($cart)
                    <button type="button" x-on:click="openCart()" class="relative shrink-0 p-2 lg:hidden">
                        <svg class="size-6 text-[var(--accent)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13l-1.4 7h12.8"/>
                        </svg>
                        <span class="price absolute -right-1 -top-1 flex size-5 items-center justify-center rounded-full text-xs font-bold leading-none text-white" style="background: var(--accent)">{{ $cartCount }}</span>
                    </button>
                @endif
            </div>
    </div>
</header>

{{-- ══ MAIN SHELL: content column + desktop cart sidebar ══ --}}
<div class="mx-auto max-w-7xl px-4 lg:flex lg:items-start lg:gap-10 lg:px-8">

    <div class="lg:flex-1 min-w-0">

        {{-- ── PRODUCT LIST / GRID ── --}}
        <main class="py-6 space-y-10 pb-36 lg:pb-12">
            @foreach($categories as $category)
                <section id="cat-{{ $category->slug }}" data-slug="{{ $category->slug }}" class="scroll-mt-16">
                    <div class="mb-3 flex items-baseline gap-2.5 px-1">
                        <h2 class="font-display text-[17px] font-extrabold tracking-tight lg:text-xl">
                            {{ $category->name }}
                        </h2>
                        <span class="price text-[12px] font-medium text-[var(--muted)]">{{ $category->products->count() }}</span>
                    </div>
                    {{-- A category earns the image grid only once every one of its
                         products has a photo; otherwise the text list, which is the
                         default presentation and not a degraded card. --}}
                    @if($category->uses_images)
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:gap-4 xl:grid-cols-4">
                            @foreach($category->products as $product)
                                @include('livewire.partials.product-card', ['product' => $product])
                            @endforeach
                        </div>
                    @else
                        <ul class="sm:grid sm:grid-cols-2 sm:gap-x-8">
                            @foreach($category->products as $product)
                                @include('livewire.partials.product-row', ['product' => $product])
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </main>
    </div>

    {{-- ══ DESKTOP CART SIDEBAR ══ --}}
    {{-- top-[72px] clears the sticky category bar; pt-6 lines the card up with
         the first category heading. --}}
    <aside class="hidden shrink-0 lg:sticky lg:top-[72px] lg:block lg:w-[330px] lg:pt-6">
        <div class="flex flex-col overflow-hidden rounded-2xl border border-[var(--hairline)]" style="max-height: calc(100vh - 6.5rem);">
            <div class="flex shrink-0 items-baseline justify-between border-b border-[var(--hairline)] px-4 py-3.5">
                <h2 class="font-display text-[15px] font-extrabold tracking-tight">Η παραγγελία σου</h2>
                @if($cart)
                    <span class="price text-[12px] font-medium text-[var(--muted)]">{{ $cartCountLabel }}</span>
                @endif
            </div>

            <div class="flex-1 overflow-y-auto px-4 py-2">
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
                        <p class="text-[13.5px] font-semibold">Άδειο καλάθι</p>
                        <p class="mt-1 text-[12px] leading-relaxed text-[var(--ink-soft)]">Διάλεξε κάτι από το μενού και θα εμφανιστεί εδώ.</p>
                    </div>
                @endforelse
            </div>

            @if($cart)
            <div class="shrink-0 border-t border-[var(--hairline)] bg-[var(--sunken)] px-4 py-4">
                <div class="mb-3 flex items-baseline justify-between">
                    <span class="text-[13px] font-medium text-[var(--ink-soft)]">Σύνολο</span>
                    <span class="price font-display text-xl font-extrabold" style="color: var(--accent)">{{ number_format($cartSubtotal, 2, ',', '.') }} €</span>
                </div>
                <a
                    href="/checkout"
                    class="block w-full rounded-xl px-4 py-3 text-center text-[14px] font-semibold text-white transition active:scale-95"
                    style="background: var(--accent);"
                >Ολοκλήρωση</a>
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
        class="flex w-full items-center gap-3 rounded-xl px-4 py-3.5 text-white shadow-2xl transition active:scale-95"
        style="background: var(--accent);"
    >
        <span class="price grid size-7 shrink-0 place-items-center rounded-lg bg-white/20 text-[13px] font-bold">{{ $cartCount }}</span>
        <span class="text-[14px] font-semibold">Ολοκλήρωση</span>
        <span class="price font-display ml-auto text-[15px] font-extrabold">{{ number_format($cartSubtotal, 2, ',', '.') }} €</span>
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
    class="fixed bottom-0 left-0 right-0 z-50 bg-white rounded-t-3xl shadow-2xl flex flex-col lg:hidden"
    style="max-height: 85vh; padding-bottom: env(safe-area-inset-bottom);"
    x-cloak
>
    {{-- Drag handle --}}
    <div class="flex justify-center pt-3 pb-1 shrink-0">
        <div class="w-10 h-1 bg-gray-200 rounded-full"></div>
    </div>

    {{-- Title row --}}
    <div class="flex shrink-0 items-center justify-between border-b border-[var(--hairline)] px-5 py-3.5">
        <h2 class="font-display text-base font-extrabold tracking-tight">Η παραγγελία σου</h2>
        <button type="button" x-on:click="closeCart()" class="text-[13px] font-medium text-[var(--ink-soft)]">Κλείσιμο</button>
    </div>

    {{-- Items --}}
    <div class="flex-1 overflow-y-auto px-4 py-2">
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
                <p class="mt-1 text-[13px] leading-relaxed text-[var(--ink-soft)]">Διάλεξε κάτι από το μενού και θα εμφανιστεί εδώ.</p>
            </div>
        @endforelse
    </div>

    {{-- Footer: subtotal + CTA --}}
    @if($cart)
    <div class="shrink-0 border-t border-[var(--hairline)] bg-[var(--sunken)] px-4 pb-4 pt-4">
        <div class="mb-3 flex items-baseline justify-between">
            <span class="text-sm font-medium text-[var(--ink-soft)]">Σύνολο</span>
            <span class="price font-display text-2xl font-extrabold" style="color: var(--accent)">{{ number_format($cartSubtotal, 2, ',', '.') }} €</span>
        </div>
        <a
            href="/checkout"
            class="block w-full rounded-xl px-4 py-3.5 text-center text-[15px] font-semibold text-white transition active:scale-95"
            style="background: var(--accent);"
        >Ολοκλήρωση παραγγελίας</a>
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
    <div class="relative z-10 flex w-full min-h-0 max-h-[calc(100dvh-env(safe-area-inset-top))] flex-col rounded-t-3xl bg-white shadow-2xl lg:mx-4 lg:max-h-[88vh] lg:max-w-lg lg:rounded-3xl"
        style="padding-bottom: env(safe-area-inset-bottom);">

        {{-- Drag handle --}}
        <div class="flex shrink-0 justify-center pb-2 pt-3 lg:hidden">
            <div class="h-1 w-10 rounded-full bg-[var(--hairline)]"></div>
        </div>

        {{-- Product name + close --}}
        <div class="flex shrink-0 items-start justify-between gap-3 border-b border-[var(--hairline)] px-5 pb-3.5">
            <div class="min-w-0">
                <h3 class="font-display text-lg font-extrabold leading-tight tracking-tight">{{ $openProduct->name }}</h3>
                <p class="price mt-0.5 text-sm font-bold" style="color: var(--accent)">
                    {{ number_format($openProduct->base_price, 2, ',', '.') }} €
                </p>
            </div>
            <button
                type="button"
                wire:click="closeModal"
                aria-label="Κλείσιμο"
                class="grid size-9 shrink-0 place-items-center rounded-lg border border-[var(--hairline)] text-[var(--ink-soft)] transition hover:border-[var(--accent)] hover:text-[var(--accent)]"
            >
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 5l10 10M15 5L5 15"/></svg>
            </button>
        </div>

        {{-- Scrollable options --}}
        <div
            x-on:touchmove.stop
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-4 space-y-6"
            style="-webkit-overflow-scrolling: touch; touch-action: pan-y;"
        >
            @foreach($openProduct->optionGroups as $group)
                <div>
                    {{-- The limits are stated up front rather than left for the
                         validation error to explain after the fact. --}}
                    <div class="mb-2.5 flex items-baseline gap-2">
                        <h4 class="font-display text-[15px] font-extrabold tracking-tight">{{ $group->name }}</h4>
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
                                    <span class="flex items-center gap-3 rounded-2xl border border-[var(--hairline)] bg-white px-4 py-3 transition hover:border-[var(--accent)] peer-checked:border-[var(--accent)] peer-checked:bg-[var(--accent-light)] peer-checked:text-[var(--accent-text)] peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--accent)]">
                                        <span class="flex-1 text-[14.5px] font-medium leading-snug">{{ $value->name }}</span>
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
                                    <span class="flex items-center gap-3 rounded-2xl border border-[var(--hairline)] bg-white px-4 py-3 transition hover:border-[var(--accent)] peer-checked:border-[var(--accent)] peer-checked:bg-[var(--accent-light)] peer-checked:text-[var(--accent-text)] peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-[var(--accent)]">
                                        <span class="flex-1 text-[14.5px] font-medium leading-snug">{{ $value->name }}</span>
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
                <label for="item-notes" class="mb-2 block font-display text-[15px] font-extrabold tracking-tight">Σημειώσεις</label>
                <input type="text"
                    id="item-notes"
                    wire:model="itemNotes"
                    placeholder="π.χ. χωρίς ζάχαρη"
                    class="w-full rounded-xl border border-[var(--hairline)] bg-[var(--sunken)] px-4 py-3 text-[14.5px] transition placeholder:text-[var(--muted)] focus:border-[var(--accent)] focus:bg-white focus:outline-none">
            </div>
        </div>

        {{-- Sticky footer: qty + add button. The running total lives inside the
             button so the customer never has to do the arithmetic before
             committing to it. --}}
        <div class="shrink-0 border-t border-[var(--hairline)] bg-[var(--sunken)] px-5 pb-4 pt-3.5">
            @error('options')
                <p class="mb-2.5 rounded-lg bg-red-50 px-3 py-2 text-[12.5px] font-medium text-red-700">{{ $message }}</p>
            @enderror

            <div class="flex items-center gap-3">
                {{-- Qty --}}
                <div class="flex shrink-0 items-center gap-1.5">
                    <button type="button"
                        x-on:click="if (quantity > 1) quantity--"
                        aria-label="Μείωση ποσότητας"
                        class="grid size-11 place-items-center rounded-xl border border-[var(--hairline)] bg-white text-[var(--ink-soft)] transition hover:border-[var(--accent)] hover:text-[var(--accent)] active:scale-90">
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M5 10h10"/></svg>
                    </button>
                    <span class="price w-7 text-center text-[15px] font-bold" x-text="quantity"></span>
                    <button type="button"
                        x-on:click="quantity++"
                        aria-label="Αύξηση ποσότητας"
                        class="grid size-11 place-items-center rounded-xl border border-[var(--hairline)] bg-white text-[var(--ink-soft)] transition hover:border-[var(--accent)] hover:text-[var(--accent)] active:scale-90">
                        <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M10 5v10M5 10h10"/></svg>
                    </button>
                </div>
                {{-- CTA --}}
                <button
                    type="button"
                    x-on:click="$wire.set('quantity', quantity); $wire.set('selectedOptions', selectedOptions); $wire.addToCart()"
                    x-bind:disabled="!canAdd"
                    x-bind:class="canAdd ? 'active:scale-95' : 'opacity-40 cursor-not-allowed'"
                    class="flex-1 rounded-xl px-4 py-3.5 text-[15px] font-semibold text-white transition"
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
