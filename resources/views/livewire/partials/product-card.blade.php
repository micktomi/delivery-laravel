{{--
    Image grid card — used only for a category where every product has an
    uploaded photo, so image_url is never null here and there is no empty
    <img> to guard against.

    Hairline and radius, no drop shadow: a grid of shadowed cards makes the
    photos fight each other, and the border does the same job quietly.
--}}
@php
    $available = $product->is_available;
    $hasOptions = $product->optionGroups->isNotEmpty();
@endphp

<button
    type="button"
    wire:key="card-{{ $product->id }}"
    @if($available)
        wire:click="{{ $hasOptions ? 'openProduct' : 'addDirectly' }}({{ $product->id }})"
    @else
        disabled
    @endif
    class="group flex flex-col overflow-hidden rounded-2xl border border-[var(--hairline)] text-left transition hover:border-[var(--accent)] disabled:cursor-not-allowed disabled:opacity-55"
>
    <img
        src="{{ $product->image_url }}"
        alt="{{ $product->name }}"
        loading="lazy"
        decoding="async"
        class="aspect-square w-full object-cover"
    >

    <div class="flex flex-1 flex-col p-3">
        {{-- Fixed title height keeps a row of cards level whatever the name does. --}}
        <p class="clamp-2 min-h-[34px] text-[13.5px] font-semibold leading-snug">{{ $product->name }}</p>

        <div class="mt-2 flex items-center justify-between gap-2">
            <span class="price text-[15px] font-bold">
                {{ number_format($product->base_price, 2, ',', '.') }} €
            </span>

            @if(! $available)
                <span class="text-[11px] font-semibold text-[var(--ink-soft)]">Εξαντλήθηκε</span>
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
        </div>
    </div>
</button>
