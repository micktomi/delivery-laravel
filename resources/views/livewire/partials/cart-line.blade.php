{{--
    One cart line, rendered by Livewire from $cart — the same array the server
    already owns. It used to be drawn client-side by an Alpine x-for over a
    mirror of that array, which put two renderers on one piece of DOM: Alpine
    inserted the rows, then every Livewire round-trip morphed a document that
    did not contain them.

    $line      — the cart line
    $index     — its position, the argument updateQty/removeFromCart expect
    $key       — stable identity, see the signature built in menu-page
    $scope     — 'desktop' or 'mobile'; keys must not collide between the copies
    $compact   — the desktop sidebar, which has less room than the mobile sheet
    $deletable — adds an explicit delete control alongside the stepper
--}}
@php
    $compact = $compact ?? false;
    $deletable = $deletable ?? false;
    $options = collect($line['selected_options'] ?? [])->pluck('value')->filter()->implode(' · ');
@endphp

<div
    wire:key="{{ $scope }}-cart-line-{{ $key }}"
    class="flex items-start gap-3 border-b border-[var(--hairline)] last:border-0 {{ $compact ? 'py-3' : 'py-4' }}"
>
    <div class="min-w-0 flex-1">
        <div class="font-semibold leading-snug {{ $compact ? 'text-[13.5px]' : 'text-base' }}">
            {{ $line['product_name'] }}
        </div>

        @if($options !== '')
            <div class="mt-0.5 leading-snug text-[var(--muted)] {{ $compact ? 'text-[11.5px]' : 'text-sm' }}">
                {{ $options }}
            </div>
        @endif

        @if(! empty($line['notes']))
            <div class="mt-0.5 {{ $compact ? 'text-[11.5px]' : 'text-sm' }}" style="color: var(--accent-text)">
                📝 {{ $line['notes'] }}
            </div>
        @endif

        <div class="price mt-1 font-bold {{ $compact ? 'text-[13.5px]' : 'text-[15px]' }}" style="color: var(--accent)">
            {{ number_format((float) $line['line_total'], 2, ',', '.') }} €
        </div>
    </div>

    <div class="mt-0.5 flex shrink-0 items-center gap-1">
        {{-- updateQty routes a quantity of 0 to removeFromCart server-side, so
             one control covers both decrement and delete. --}}
        <button
            type="button"
            wire:click="updateQty({{ $index }}, {{ $line['quantity'] - 1 }})"
            aria-label="Μείωση ποσότητας"
            class="grid {{ $compact ? 'size-7' : 'size-8' }} place-items-center rounded-lg border border-[var(--hairline)] text-[var(--ink-soft)] transition hover:border-[var(--accent)] hover:text-[var(--accent)] active:scale-90"
        >
            <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M5 10h10"/></svg>
        </button>

        <span class="price w-6 text-center text-[13px] font-bold">{{ $line['quantity'] }}</span>

        <button
            type="button"
            wire:click="updateQty({{ $index }}, {{ $line['quantity'] + 1 }})"
            aria-label="Αύξηση ποσότητας"
            class="grid {{ $compact ? 'size-7' : 'size-8' }} place-items-center rounded-lg border border-[var(--hairline)] text-[var(--ink-soft)] transition hover:border-[var(--accent)] hover:text-[var(--accent)] active:scale-90"
        >
            <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M10 5v10M5 10h10"/></svg>
        </button>

        @if($deletable)
            <button
                type="button"
                wire:click="removeFromCart({{ $index }})"
                aria-label="Αφαίρεση από το καλάθι"
                class="ml-1.5 grid size-8 place-items-center text-[var(--muted)] transition hover:text-red-600"
            >
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M4 7h16"/></svg>
            </button>
        @endif
    </div>
</div>
