@php
    /* Presentation only. The component hands over id, name and is_available
       and nothing else: this page sits behind a shared PIN, so category
       names, images, prices and descriptions are deliberately kept off it
       (see KitchenAvailabilityTest). */
    $offCount = $products->where('is_available', false)->count();
@endphp

<main class="min-h-dvh bg-stone-100 text-stone-950">
    {{-- Sticky header: one compact row of chrome, then the search. Both stay
         on screen while the list scrolls so a search is always one tap away. --}}
    <header class="sticky top-0 z-10 border-b border-stone-300 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-3 py-2.5 sm:px-5">
            <div class="flex min-w-0 items-baseline gap-x-3">
                <h1 class="truncate font-display text-lg font-extrabold tracking-tight sm:text-2xl">Διαθεσιμότητα</h1>
                <span class="hidden text-xs font-semibold uppercase tracking-[0.14em] text-stone-400 sm:inline">Κουζίνα</span>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a
                    href="{{ route('kitchen') }}"
                    class="flex min-h-11 items-center rounded-xl bg-stone-900 px-3 text-sm font-bold text-white transition hover:bg-stone-700 active:scale-95 sm:px-4"
                >
                    {{-- Short label on a phone so the title keeps its room; the
                         full label from sm up. --}}
                    <span class="sm:hidden">← Κουζίνα</span>
                    <span class="hidden sm:inline">Πίσω στην Κουζίνα</span>
                </a>
                <button
                    type="button"
                    wire:click="logout"
                    wire:loading.attr="disabled"
                    wire:target="logout"
                    class="min-h-11 shrink-0 rounded-xl border border-stone-300 bg-white px-3 text-sm font-bold text-stone-700 transition hover:bg-stone-100 active:scale-95 disabled:opacity-60 sm:px-4"
                >
                    <span class="sm:hidden">Έξοδος</span>
                    <span class="hidden sm:inline">Αποσύνδεση</span>
                </button>
            </div>
        </div>

        <div class="mx-auto flex max-w-6xl items-center gap-3 px-3 pb-3 sm:px-5">
            <label for="product-search" class="sr-only">Αναζήτηση προϊόντος</label>
            <div class="relative min-w-0 flex-1">
                <svg class="pointer-events-none absolute inset-y-0 left-4 my-auto size-5 text-stone-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input
                    id="product-search"
                    wire:model.live.debounce.250ms="search"
                    type="search"
                    inputmode="search"
                    autocomplete="off"
                    placeholder="Αναζήτηση προϊόντος…"
                    class="min-h-14 w-full rounded-xl border border-stone-300 bg-stone-50 py-2 pl-12 pr-4 text-lg font-semibold text-stone-950 placeholder:font-medium placeholder:text-stone-400 focus:border-amber-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-amber-500/30"
                >
            </div>
            {{-- Operational glance: how many products are switched off right now
                 (within the current search). Hidden when everything is on. --}}
            @if($offCount > 0)
                <div class="shrink-0 rounded-lg bg-stone-200 px-3 py-2 text-sm font-bold tabular-nums text-stone-700">
                    {{ $offCount }} <span class="font-semibold text-stone-500">εκτός</span>
                </div>
            @endif
        </div>
    </header>

    {{-- 1 column on a phone, 2 on a tablet, 3 from 1280px: each row is
         name + one large toggle, so three across still reads in one sweep. --}}
    <section class="mx-auto max-w-6xl px-3 py-3 sm:px-5 sm:py-4" aria-live="polite">
        @if($products->isEmpty())
            <div class="rounded-2xl border-2 border-dashed border-stone-300 px-5 py-12 text-center text-lg font-bold text-stone-500">
                Δεν βρέθηκαν προϊόντα.
            </div>
        @else
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2 md:gap-3 xl:grid-cols-3">
                @foreach($products as $product)
                    <article
                        wire:key="availability-product-{{ $product->id }}"
                        data-availability-row
                        data-available="{{ $product->is_available ? 'true' : 'false' }}"
                        class="flex min-h-20 items-center gap-3 rounded-xl border p-2.5 pl-4 sm:p-3 sm:pl-5
                            {{ $product->is_available
                                ? 'border-stone-200 bg-white'
                                : 'border-stone-300 bg-stone-200/70' }}"
                    >
                        <div class="min-w-0 flex-1">
                            <h2 class="break-words text-lg font-bold leading-tight sm:text-xl {{ $product->is_available ? 'text-stone-950' : 'text-stone-500' }}">
                                {{ $product->name }}
                            </h2>
                            @unless($product->is_available)
                                <p class="mt-0.5 text-[11px] font-extrabold uppercase tracking-wide text-stone-500">Μη διαθέσιμο</p>
                            @endunless
                        </div>

                        <button
                            type="button"
                            wire:click="setAvailability({{ $product->id }}, {{ $product->is_available ? 'false' : 'true' }})"
                            wire:loading.attr="disabled"
                            wire:target="setAvailability({{ $product->id }}, {{ $product->is_available ? 'false' : 'true' }})"
                            aria-pressed="{{ $product->is_available ? 'true' : 'false' }}"
                            aria-label="{{ $product->name }}: {{ $product->is_available ? 'διαθέσιμο' : 'μη διαθέσιμο' }}"
                            class="flex min-h-14 w-28 shrink-0 touch-manipulation items-center justify-center gap-2 rounded-xl border-2 text-lg font-extrabold tracking-wide transition active:scale-95 disabled:opacity-60 sm:w-32
                                {{ $product->is_available
                                    ? 'border-emerald-600 bg-emerald-600 text-white hover:bg-emerald-700'
                                    : 'border-stone-400 bg-white text-stone-600 hover:border-stone-500 hover:bg-stone-50' }}"
                        >
                            @if($product->is_available)
                                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>
                                ON
                            @else
                                <span class="size-3 rounded-full border-2 border-stone-400" aria-hidden="true"></span>
                                OFF
                            @endif
                        </button>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</main>
