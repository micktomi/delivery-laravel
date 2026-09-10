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
    $options = \App\Services\OptionsPresenter::format($line['selected_options'] ?? []);
    $control = $compact ? 'size-9' : 'size-11';
@endphp

<div
    wire:key="{{ $scope }}-cart-line-{{ $key }}"
    class="border-b border-[var(--hairline)] py-3 last:border-0"
>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="truncate text-sm font-semibold leading-snug">
                {{ $line['product_name'] }}
            </p>

            @if($options !== '')
                <p class="mt-0.5 text-[13px] leading-snug text-[var(--muted)]">
                    {{ $options }}
                </p>
            @endif

            @if(! empty($line['notes']))
                <p class="mt-0.5 text-[13px] leading-snug text-amber-800">
                    {{ $line['notes'] }}
                </p>
            @endif
        </div>

        <span class="price shrink-0 text-sm font-bold">
            {{ number_format((float) $line['line_total'], 2, ',', '.') }} €
        </span>
    </div>

    <div class="mt-2 flex items-center gap-1">
        {{-- Send intent only: the server applies it to the latest cart state.
             Decrementing one follows the existing remove-at-zero behavior. --}}
        <button
            type="button"
            wire:click="decrementQty({{ $index }})"
            aria-label="Μείωση ποσότητας"
            class="grid {{ $control }} place-items-center rounded-lg border border-[var(--hairline)] bg-white text-[var(--ink)] transition hover:border-[var(--muted)] active:scale-95"
        >
            <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M5 10h10"/></svg>
        </button>

        <span class="price w-8 text-center text-sm font-bold">{{ $line['quantity'] }}</span>

        <button
            type="button"
            wire:click="incrementQty({{ $index }})"
            aria-label="Αύξηση ποσότητας"
            class="grid {{ $control }} place-items-center rounded-lg border border-[var(--hairline)] bg-white text-[var(--ink)] transition hover:border-[var(--muted)] active:scale-95"
        >
            <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M10 5v10M5 10h10"/></svg>
        </button>

        <button
            type="button"
            wire:click="removeFromCart({{ $index }})"
            class="ml-auto {{ $compact ? 'min-h-9' : 'min-h-11' }} px-2 text-[13px] font-medium text-[var(--muted)] transition hover:text-red-700"
        >Αφαίρεση</button>
    </div>
</div>
