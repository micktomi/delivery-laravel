@php use App\Enums\SelectionType; @endphp
<div
    x-data="{
        cartOpen:    false,
        activeSlug:  '{{ $categories->first()?->slug ?? '' }}',
        cart: @js($cart),

        get cartCount()  { return this.cart.reduce((s, i) => s + i.quantity, 0); },
        get subtotal()   { return this.cart.reduce((s, i) => s + parseFloat(i.line_total), 0); },

        /* ── scroll-spy ── */
        initScrollSpy() {
            const obs = new IntersectionObserver(entries => {
                entries.forEach(e => {
                    if (e.isIntersecting) {
                        this.activeSlug = e.target.dataset.slug;
                        /* scroll the matching pill into view — horizontally, inside the pill nav only.
                           (scrollIntoView() was walking up to the window for the vertical axis and
                           snapping the whole page back toward the header, since the hero above it
                           means the header isn't already at y=0.) */
                        const pill = document.querySelector(`[data-pill='${this.activeSlug}']`);
                        if (pill) {
                            const nav = pill.parentElement;
                            nav.scrollTo({
                                left: pill.offsetLeft - (nav.clientWidth - pill.clientWidth) / 2,
                                behavior: 'smooth',
                            });
                        }
                    }
                });
            }, { rootMargin: '-96px 0px -55% 0px', threshold: 0 });
            document.querySelectorAll('[data-slug]').forEach(el => obs.observe(el));
        }
    }"
    x-init="initScrollSpy()"
    x-on:cart-updated.window="cart = $event.detail.cart || []; cartOpen = true"
    class="min-h-screen bg-gray-50 overflow-x-hidden"
>

{{-- ══ HERO ══ --}}
<section class="hero-gradient text-white">
    <div class="max-w-6xl mx-auto px-5 sm:px-8 py-10 sm:py-14">
        <h1 class="text-3xl sm:text-4xl font-black tracking-tight">{{ config('app.name') }}</h1>
        <p class="mt-2 text-base sm:text-lg font-semibold text-white/90">Καφές • Sandwich • Αναψυκτικά</p>
        <p class="mt-1 text-sm text-white/70">Γρήγορη παραγγελία για delivery / take away</p>
    </div>
</section>

{{-- ══ MAIN SHELL: content column + desktop cart sidebar ══ --}}
<div class="max-w-6xl mx-auto lg:flex lg:items-start lg:gap-8 lg:px-8">

    <div class="lg:flex-1 min-w-0">

        {{-- ── STICKY HEADER: category pills + mobile cart icon ── --}}
        <header class="sticky top-0 z-30 bg-white shadow-sm">
            <div class="flex items-center justify-between px-4 py-2.5 lg:hidden">
                <span class="text-sm font-black tracking-tight text-gray-900">☕ {{ config('app.name') }}</span>
                {{-- Cart icon (mobile backup access) --}}
                <button
                    x-show="cartCount > 0"
                    x-on:click="cartOpen = true"
                    class="relative p-2"
                    x-cloak
                >
                    <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13l-1.4 7h12.8"/>
                    </svg>
                    <span x-text="cartCount"
                        class="absolute -top-1 -right-1 bg-amber-500 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center leading-none">
                    </span>
                </button>
            </div>

            {{-- Category pills (scroll-spy highlight) --}}
            <nav class="flex gap-2 px-4 py-3 lg:py-4 overflow-x-auto scrollbar-hide lg:justify-center"
                style="scroll-snap-type: x mandatory;">
                @foreach($categories as $category)
                    <a
                        href="#cat-{{ $category->slug }}"
                        data-pill="{{ $category->slug }}"
                        x-bind:class="activeSlug === '{{ $category->slug }}'
                            ? 'bg-amber-500 text-white'
                            : 'bg-gray-100 text-gray-700'"
                        class="whitespace-nowrap px-4 py-2 rounded-full text-sm font-semibold transition-colors shrink-0"
                        style="scroll-snap-align: start;"
                        x-on:click.prevent="
                            document.getElementById('cat-{{ $category->slug }}').scrollIntoView({ behavior: 'smooth', block: 'start' });
                        "
                    >{{ $category->name }}</a>
                @endforeach
            </nav>
        </header>

        {{-- ── PRODUCT GRID ── --}}
        <main class="px-4 lg:px-0 py-6 space-y-10 pb-36 lg:pb-12">
            @foreach($categories as $category)
                <section id="cat-{{ $category->slug }}" data-slug="{{ $category->slug }}">
                    <h2 class="text-base font-bold text-gray-500 uppercase tracking-wider mb-3 px-1">
                        {{ $category->name }}
                    </h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 gap-3">
                        @foreach($category->products as $product)
                            @php $available = $product->is_available; @endphp
                            <div
                                class="bg-white rounded-2xl shadow-sm p-4 flex flex-col transition hover:shadow-md
                                    {{ !$available ? 'opacity-50' : '' }}"
                                @if($available)
                                    @if($product->optionGroups->isNotEmpty())
                                        wire:click="openProduct({{ $product->id }})"
                                    @else
                                        wire:click="addDirectly({{ $product->id }})"
                                    @endif
                                @endif
                                {{ $available ? 'role=button' : '' }}
                            >
                                <div class="font-semibold text-gray-900 text-base leading-snug">{{ $product->name }}</div>
                                @if($product->description)
                                    <div class="text-sm text-gray-400 mt-0.5">{{ $product->description }}</div>
                                @endif

                                <div class="mt-3 flex items-center justify-between">
                                    <span class="font-bold text-lg" style="color: var(--accent)">
                                        {{ number_format($product->base_price, 2) }}€
                                    </span>
                                    @if($available)
                                        <span class="w-10 h-10 rounded-full flex items-center justify-center text-2xl font-bold text-white shadow shrink-0"
                                            style="background: var(--accent)">
                                            +
                                        </span>
                                    @else
                                        <span class="text-xs text-gray-400 font-medium whitespace-nowrap">Εξαντλήθηκε</span>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </main>
    </div>

    {{-- ══ DESKTOP CART SIDEBAR ══ --}}
    <aside class="hidden lg:block lg:w-[360px] shrink-0">
        <div class="sticky top-24 bg-white rounded-2xl shadow-sm flex flex-col" style="max-height: calc(100vh - 7rem);">
            <div class="px-5 py-4 border-b shrink-0">
                <h2 class="font-black text-lg">Η παραγγελία σου</h2>
            </div>

            <div class="flex-1 overflow-y-auto px-4 py-2">
                <template x-if="cart.length === 0">
                    <p class="text-center text-gray-400 py-10 text-sm">Το καλάθι είναι άδειο</p>
                </template>
                <template x-for="(item, idx) in cart" :key="idx">
                    <div class="flex items-start gap-3 py-3 border-b border-gray-100 last:border-0">
                        <div class="flex-1 min-w-0">
                            <div class="font-semibold text-sm leading-snug" x-text="item.product_name"></div>
                            <template x-if="item.selected_options && item.selected_options.length">
                                <div class="text-xs text-gray-400 mt-0.5 leading-snug"
                                    x-text="item.selected_options.map(o => o.value).join(' · ')">
                                </div>
                            </template>
                            <div class="font-bold text-sm mt-1" style="color: var(--accent)"
                                x-text="parseFloat(item.line_total).toFixed(2) + '€'">
                            </div>
                        </div>
                        <div class="flex items-center gap-1 shrink-0 mt-0.5">
                            <button
                                class="w-7 h-7 rounded-full border-2 border-gray-200 flex items-center justify-center text-sm font-bold active:scale-90 transition"
                                x-on:click="
                                    if (item.quantity > 1) {
                                        item.quantity--;
                                        $wire.updateQty(idx, item.quantity);
                                    } else {
                                        cart.splice(idx, 1);
                                        $wire.removeFromCart(idx);
                                    }
                                "
                            >−</button>
                            <span class="w-6 text-center font-bold text-sm" x-text="item.quantity"></span>
                            <button
                                class="w-7 h-7 rounded-full border-2 border-gray-200 flex items-center justify-center text-sm font-bold active:scale-90 transition"
                                x-on:click="item.quantity++; $wire.updateQty(idx, item.quantity);"
                            >+</button>
                        </div>
                    </div>
                </template>
            </div>

            <div class="px-5 pt-3 pb-5 border-t shrink-0" x-show="cart.length > 0">
                <div class="flex justify-between items-baseline mb-3">
                    <span class="text-gray-500 text-sm" x-text="cartCount + ' ' + (cartCount === 1 ? 'προϊόν' : 'προϊόντα')"></span>
                    <span class="font-black text-xl" style="color: var(--accent)" x-text="subtotal.toFixed(2) + '€'"></span>
                </div>
                <a
                    href="/checkout"
                    class="block w-full py-3.5 text-white text-center font-black text-base rounded-2xl shadow-lg active:scale-95 transition"
                    style="background: var(--accent);"
                >Συνέχεια</a>
            </div>
        </div>
    </aside>
</div>

{{-- ══ MOBILE STICKY BOTTOM CART BAR ══ --}}
<div
    x-show="cartCount > 0"
    x-cloak
    class="fixed bottom-0 left-0 right-0 z-40 px-4 pb-4 lg:hidden"
    style="padding-bottom: max(1rem, env(safe-area-inset-bottom));"
>
    <button
        x-on:click="cartOpen = true"
        class="w-full flex items-center justify-between text-white font-bold rounded-2xl px-5 py-4 shadow-2xl active:scale-95 transition"
        style="background: var(--accent);"
    >
        <span class="flex items-center gap-2">
            🛒
            <span x-text="cartCount + ' ' + (cartCount === 1 ? 'προϊόν' : 'προϊόντα')"></span>
        </span>
        <span class="flex items-center gap-3">
            <span x-text="subtotal.toFixed(2) + '€'" class="text-lg"></span>
            <span class="opacity-80 text-sm">Συνέχεια →</span>
        </span>
    </button>
</div>

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
    x-on:click="cartOpen = false"
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
    <div class="flex items-center justify-between px-5 py-3 border-b shrink-0">
        <h2 class="font-black text-lg">Το καλάθι σου</h2>
        <button x-on:click="cartOpen = false" class="text-gray-400 text-3xl leading-none">&times;</button>
    </div>

    {{-- Items --}}
    <div class="flex-1 overflow-y-auto px-4 py-2">
        <template x-if="cart.length === 0">
            <p class="text-center text-gray-400 py-12 text-base">Το καλάθι είναι άδειο</p>
        </template>
        <template x-for="(item, idx) in cart" :key="idx">
            <div class="flex items-start gap-3 py-4 border-b border-gray-100 last:border-0">
                <div class="flex-1 min-w-0">
                    <div class="font-semibold text-base leading-snug" x-text="item.product_name"></div>
                    <template x-if="item.selected_options && item.selected_options.length">
                        <div class="text-sm text-gray-400 mt-0.5 leading-snug"
                            x-text="item.selected_options.map(o => o.value).join(' · ')">
                        </div>
                    </template>
                    <template x-if="item.notes">
                        <div class="text-sm mt-0.5" style="color: var(--accent-text)" x-text="'📝 ' + item.notes"></div>
                    </template>
                    <div class="font-bold mt-1.5" style="color: var(--accent)"
                        x-text="parseFloat(item.line_total).toFixed(2) + '€'">
                    </div>
                </div>
                {{-- Qty stepper --}}
                <div class="flex items-center gap-1 shrink-0 mt-1">
                    <button
                        class="w-8 h-8 rounded-full border-2 border-gray-200 flex items-center justify-center text-base font-bold active:scale-90 transition"
                        x-on:click="
                            if (item.quantity > 1) {
                                item.quantity--;
                                $wire.updateQty(idx, item.quantity);
                            } else {
                                cart.splice(idx, 1);
                                $wire.removeFromCart(idx);
                            }
                        "
                    >−</button>
                    <span class="w-7 text-center font-bold text-base" x-text="item.quantity"></span>
                    <button
                        class="w-8 h-8 rounded-full border-2 border-gray-200 flex items-center justify-center text-base font-bold active:scale-90 transition"
                        x-on:click="item.quantity++; $wire.updateQty(idx, item.quantity);"
                    >+</button>
                    <button
                        class="ml-2 w-8 h-8 flex items-center justify-center text-gray-300 hover:text-red-400 transition"
                        x-on:click="cart.splice(idx, 1); $wire.removeFromCart(idx);"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M4 7h16"/>
                        </svg>
                    </button>
                </div>
            </div>
        </template>
    </div>

    {{-- Footer: subtotal + CTA --}}
    <div class="px-4 pt-3 pb-4 border-t bg-white shrink-0" x-show="cart.length > 0">
        <div class="flex justify-between items-baseline mb-3">
            <span class="text-gray-500 text-base">Υποσύνολο</span>
            <span class="font-black text-xl" style="color: var(--accent)" x-text="subtotal.toFixed(2) + '€'"></span>
        </div>
        <a
            href="/checkout"
            class="block w-full py-4 text-white text-center font-black text-lg rounded-2xl shadow-lg active:scale-95 transition"
            style="background: var(--accent);"
        >Συνέχεια →</a>
    </div>
</div>

{{-- ══ PRODUCT MODAL (bottom sheet on mobile, centered dialog on desktop) ══ --}}
@if($openProduct)
<div
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
    <div class="relative w-full bg-white rounded-t-3xl lg:rounded-3xl lg:max-w-lg lg:mx-4 flex flex-col z-10"
        style="max-height: 88vh; padding-bottom: env(safe-area-inset-bottom);">

        {{-- Drag handle --}}
        <div class="flex justify-center pt-3 pb-2 shrink-0 lg:hidden">
            <div class="w-10 h-1 bg-gray-200 rounded-full"></div>
        </div>

        {{-- Product name + close --}}
        <div class="flex items-start justify-between px-5 pb-3 border-b shrink-0">
            <div>
                <h3 class="font-black text-xl leading-tight">{{ $openProduct->name }}</h3>
                <div class="text-base font-bold mt-0.5" style="color: var(--accent)">
                    {{ number_format($openProduct->base_price, 2) }}€
                </div>
            </div>
            <button wire:click="closeModal" class="text-gray-300 text-3xl leading-none ml-4 mt-1">&times;</button>
        </div>

        {{-- Scrollable options --}}
        <div class="flex-1 overflow-y-auto px-5 py-4 space-y-6">
            @foreach($openProduct->optionGroups as $group)
                <div>
                    <div class="flex items-center gap-2 mb-3">
                        <span class="font-bold text-base text-gray-900">{{ $group->name }}</span>
                        @if($group->is_required)
                            <span class="text-xs font-bold text-white bg-red-400 rounded-full px-2 py-0.5">Υποχρεωτικό</span>
                        @endif
                        @if($group->selection === SelectionType::Multi && ($group->min_select || $group->max_select))
                            <span class="text-xs text-gray-400">
                                @if($group->min_select) min {{ $group->min_select }} @endif
                                @if($group->max_select) / max {{ $group->max_select }} @endif
                            </span>
                        @endif
                    </div>

                    @if($group->selection === SelectionType::Single)
                        <div class="space-y-2">
                            @foreach($group->optionValues as $value)
                                <label
                                    class="flex items-center gap-4 px-4 py-3.5 rounded-xl border-2 cursor-pointer transition"
                                    x-bind:class="selectedOptions[{{ $group->id }}] == {{ $value->id }}
                                        ? 'border-amber-400 bg-amber-50'
                                        : 'border-gray-100 bg-gray-50'"
                                >
                                    <input type="radio"
                                        name="g{{ $group->id }}"
                                        value="{{ $value->id }}"
                                        x-model="selectedOptions[{{ $group->id }}]"
                                        class="w-5 h-5 accent-amber-500 shrink-0">
                                    <span class="flex-1 font-medium text-base">{{ $value->name }}</span>
                                    @if($value->price_delta > 0)
                                        <span class="font-bold text-sm" style="color: var(--accent)">
                                            +{{ number_format($value->price_delta, 2) }}€
                                        </span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    @else
                        <div class="space-y-2">
                            @foreach($group->optionValues as $value)
                                <label
                                    class="flex items-center gap-4 px-4 py-3.5 rounded-xl border-2 cursor-pointer transition"
                                    x-bind:class="(selectedOptions[{{ $group->id }}] || []).includes({{ $value->id }})
                                        ? 'border-amber-400 bg-amber-50'
                                        : 'border-gray-100 bg-gray-50'"
                                >
                                    <input type="checkbox"
                                        x-bind:checked="(selectedOptions[{{ $group->id }}] || []).includes({{ $value->id }})"
                                        x-on:change="toggleMulti({{ $group->id }}, {{ $value->id }})"
                                        class="w-5 h-5 accent-amber-500 shrink-0 rounded">
                                    <span class="flex-1 font-medium text-base">{{ $value->name }}</span>
                                    @if($value->price_delta > 0)
                                        <span class="font-bold text-sm" style="color: var(--accent)">
                                            +{{ number_format($value->price_delta, 2) }}€
                                        </span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- Notes --}}
            <div>
                <label class="block font-bold text-base text-gray-900 mb-2">Σημειώσεις</label>
                <input type="text"
                    wire:model="itemNotes"
                    placeholder="π.χ. χωρίς ζάχαρη"
                    class="w-full border-2 border-gray-100 bg-gray-50 rounded-xl px-4 py-3 text-base focus:outline-none focus:border-amber-300">
            </div>
        </div>

        {{-- Sticky footer: qty + add button --}}
        <div class="shrink-0 px-5 pt-3 pb-4 border-t bg-white">
            @error('options')
                <p class="text-red-500 text-sm mb-2">{{ $message }}</p>
            @enderror

            <div class="flex items-center gap-4 mb-3">
                {{-- Qty --}}
                <div class="flex items-center gap-3 bg-gray-100 rounded-xl px-3 py-2">
                    <button x-on:click="if (quantity > 1) quantity--"
                        class="w-8 h-8 flex items-center justify-center font-black text-xl active:scale-90 transition">−</button>
                    <span class="w-6 text-center font-black text-lg" x-text="quantity"></span>
                    <button x-on:click="quantity++"
                        class="w-8 h-8 flex items-center justify-center font-black text-xl active:scale-90 transition">+</button>
                </div>
                {{-- CTA --}}
                <button
                    x-on:click="$wire.set('quantity', quantity); $wire.set('selectedOptions', selectedOptions); $wire.addToCart()"
                    x-bind:disabled="!canAdd"
                    x-bind:class="canAdd ? 'active:scale-95' : 'opacity-40 cursor-not-allowed'"
                    class="flex-1 py-4 text-white font-black text-lg rounded-2xl shadow-lg transition"
                    style="background: var(--accent);"
                >
                    Προσθήκη — <span x-text="lineTotal.toFixed(2) + '€'"></span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>
