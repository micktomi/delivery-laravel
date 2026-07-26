{{--
    Text list row — the default presentation for a category.

    Not a card with its photo missing: the name is the scan target, the prices
    form a real right-hand column, and the trailing control says which of the
    two things a tap does. A product with option groups opens the modal and
    shows a chevron; one without is added straight away and shows a plus.
--}}
@php
    $available = $product->is_available;
    $hasOptions = $product->optionGroups->isNotEmpty();
@endphp

<li class="border-b border-[var(--hairline)]" wire:key="row-{{ $product->id }}">
    <button
        type="button"
        @if($available)
            wire:click="{{ $hasOptions ? 'openProduct' : 'addDirectly' }}({{ $product->id }})"
        @else
            disabled
        @endif
        class="group flex w-full items-center gap-3 py-3 text-left disabled:cursor-not-allowed disabled:opacity-55"
    >
        <div class="min-w-0 flex-1">
            <p class="clamp-2 text-[14.5px] font-semibold leading-snug transition group-hover:text-[var(--accent-text)]">
                {{ $product->name }}
            </p>
            @if($product->description)
                <p class="clamp-1 mt-0.5 text-[12px] text-[var(--muted)]">{{ $product->description }}</p>
            @endif
        </div>

        <span class="price shrink-0 text-[14.5px] font-bold">
            {{ number_format($product->base_price, 2, ',', '.') }} €
        </span>

        @if(! $available)
            <span class="shrink-0 text-[11px] font-semibold text-[var(--ink-soft)]">Εξαντλήθηκε</span>
        @elseif($hasOptions)
            <span class="grid size-8 shrink-0 place-items-center rounded-lg border border-[var(--hairline)] text-[var(--ink-soft)] transition group-hover:border-[var(--accent)] group-hover:text-[var(--accent)]">
                <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
            </span>
        @else
            <span
                x-bind:class="addButtonClass({{ $product->id }})"
                class="grid size-8 shrink-0 place-items-center rounded-lg transition"
            >
                <svg x-show="notAdded({{ $product->id }})" class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M10 5v10M5 10h10"/></svg>
                <svg x-cloak x-show="isAdded({{ $product->id }})" class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10.5l4 4 8-9"/></svg>
            </span>
        @endif
    </button>
</li>
