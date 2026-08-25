<main class="min-h-dvh bg-stone-100 text-stone-950">
    <header class="sticky top-0 z-10 border-b border-stone-200 bg-white/95 shadow-sm backdrop-blur">
        <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <div class="min-w-0">
                <p class="text-xs font-black uppercase tracking-[0.16em] text-amber-700">Kitchen staff</p>
                <h1 class="truncate text-2xl font-black tracking-tight sm:text-3xl">Διαθεσιμότητα</h1>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <a
                    href="{{ route('kitchen') }}"
                    class="flex min-h-12 items-center rounded-xl bg-stone-900 px-4 py-2 text-base font-black text-white transition hover:bg-stone-700 active:scale-95"
                >
                    Πίσω στην Κουζίνα
                </a>
                <button
                    type="button"
                    wire:click="logout"
                    wire:loading.attr="disabled"
                    wire:target="logout"
                    class="min-h-12 shrink-0 rounded-xl border-2 border-stone-300 bg-white px-4 py-2 text-base font-black text-stone-700 transition hover:bg-stone-100 active:scale-95 disabled:opacity-60"
                >
                    Αποσύνδεση
                </button>
            </div>
        </div>

        <div class="mx-auto max-w-5xl px-4 pb-4 sm:px-6">
            <label for="product-search" class="sr-only">Αναζήτηση προϊόντος</label>
            <div class="relative">
                <span class="pointer-events-none absolute inset-y-0 left-0 grid w-14 place-items-center text-2xl" aria-hidden="true">⌕</span>
                <input
                    id="product-search"
                    wire:model.live.debounce.250ms="search"
                    type="search"
                    inputmode="search"
                    autocomplete="off"
                    placeholder="Αναζήτηση προϊόντος…"
                    class="min-h-16 w-full rounded-2xl border-2 border-stone-300 bg-stone-50 py-3 pl-14 pr-4 text-xl font-bold text-stone-950 placeholder:text-stone-400 focus:border-amber-600 focus:bg-white focus:outline-none"
                >
            </div>
        </div>
    </header>

    <section class="mx-auto max-w-5xl space-y-3 px-4 py-5 sm:px-6 sm:py-7" aria-live="polite">
        @forelse($products as $product)
            <article wire:key="availability-product-{{ $product->id }}" class="flex min-h-24 items-center gap-4 rounded-2xl border-2 border-stone-200 bg-white p-4 shadow-sm sm:p-5">
                <h2 class="min-w-0 flex-1 break-words text-xl font-black leading-tight sm:text-2xl">{{ $product->name }}</h2>

                <button
                    type="button"
                    wire:click="setAvailability({{ $product->id }}, {{ $product->is_available ? 'false' : 'true' }})"
                    wire:loading.attr="disabled"
                    wire:target="setAvailability({{ $product->id }}, {{ $product->is_available ? 'false' : 'true' }})"
                    aria-pressed="{{ $product->is_available ? 'true' : 'false' }}"
                    aria-label="{{ $product->name }}: {{ $product->is_available ? 'διαθέσιμο' : 'μη διαθέσιμο' }}"
                    class="min-h-16 w-28 shrink-0 rounded-2xl border-2 px-4 py-3 text-xl font-black shadow-sm transition active:scale-95 disabled:opacity-60 sm:w-36 sm:text-2xl
                        {{ $product->is_available
                            ? 'border-emerald-700 bg-emerald-600 text-white hover:bg-emerald-700'
                            : 'border-red-300 bg-red-50 text-red-700 hover:bg-red-100' }}"
                >
                    {{ $product->is_available ? 'ON' : 'OFF' }}
                </button>
            </article>
        @empty
            <div class="rounded-2xl border-2 border-dashed border-stone-300 bg-white px-5 py-12 text-center text-xl font-bold text-stone-500">
                Δεν βρέθηκαν προϊόντα.
            </div>
        @endforelse
    </section>
</main>
