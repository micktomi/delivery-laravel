{{--
    One cart line, rendered by Livewire from $cart — the same array the server
    already owns. It used to be drawn client-side by an Alpine x-for over a
    mirror of that array, which put two renderers on one piece of DOM: Alpine
    inserted the rows, then every Livewire round-trip morphed a document that
    did not contain them.

    $line      — the cart line
    $index     — its position, the argument the cart actions expect
    $key       — stable identity, see the signature built in menu-page
    $scope     — 'desktop' or 'mobile'; keys must not collide between the copies
    $compact   — the desktop sidebar, which has less room than the mobile sheet

    Two rows rather than a name/stepper split: at 330px the controls and the
    name fight for the same width, and the line total belongs on the same
    baseline as the name it prices.
--}}
@php
    $compact = $compact ?? false;
    $options = collect($line['selected_options'] ?? [])->pluck('value')->filter()->implode(' · ');
@endphp

<div
    wire:key="{{ $scope }}-cart-line-{{ $key }}"
    class="border-b border-[var(--hairline)] last:border-0 {{ $compact ? 'py-3' : 'py-3.5' }}"
>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="truncate font-semibold leading-snug {{ $compact ? 'text-[13.5px]' : 'text-sm' }}">
                {{ $line['product_name'] }}
            </p>

            @if($options !== '')
                <p class="mt-0.5 leading-snug text-[var(--ink-soft)] {{ $compact ? 'text-[11.5px]' : 'text-[12px]' }}">
                    {{ $options }}
                </p>
            @endif

            @if(! empty($line['notes']))
                <p class="mt-0.5 {{ $compact ? 'text-[11.5px]' : 'text-[12px]' }}" style="color: var(--accent-text)">
                    📝 {{ $line['notes'] }}
                </p>
            @endif
        </div>

        <span class="price shrink-0 font-bold {{ $compact ? 'text-[13.5px]' : 'text-sm' }}">
            {{ number_format((float) $line['line_total'], 2, ',', '.') }} €
        </span>
    </div>

    <div class="mt-2 flex items-center {{ $compact ? 'gap-1' : 'gap-1.5' }}">
        {{-- Send intent only: the server applies it to the latest cart state.
             Decrementing one follows the existing remove-at-zero behavior. --}}
        <button
            type="button"
            wire:click="decrementQty({{ $index }})"
            aria-label="Μείωση ποσότητας"
            class="grid {{ $compact ? 'size-7' : 'size-9' }} place-items-center rounded-md border border-[var(--hairline)] text-[var(--ink-soft)] transition hover:border-[var(--accent)] hover:text-[var(--accent)] active:scale-90"
        >
            <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M5 10h10"/></svg>
        </button>

        <span class="price {{ $compact ? 'w-7 text-[13px]' : 'w-8 text-sm' }} text-center font-semibold">{{ $line['quantity'] }}</span>

        <button
            type="button"
            wire:click="incrementQty({{ $index }})"
            aria-label="Αύξηση ποσότητας"
            class="grid {{ $compact ? 'size-7' : 'size-9' }} place-items-center rounded-md border border-[var(--hairline)] text-[var(--ink-soft)] transition hover:border-[var(--accent)] hover:text-[var(--accent)] active:scale-90"
        >
            <svg class="size-3.5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M10 5v10M5 10h10"/></svg>
        </button>

        <button
            type="button"
            wire:click="removeFromCart({{ $index }})"
            class="ml-auto font-medium text-[var(--muted)] transition hover:text-red-600 {{ $compact ? 'text-[11.5px]' : 'text-[12px]' }}"
        >Αφαίρεση</button>
    </div>
</div>
