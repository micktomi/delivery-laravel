{{--
    Canonical storefront product card.

    Hairline and radius, no drop shadow: a grid of shadowed cards makes the
    photos fight each other, and the border does the same job quietly.
--}}
@php
    $available = $product->is_available;
    $hasOptions = $product->optionGroups->isNotEmpty();
    $placeholderTints = [
        'bg-amber-50 text-amber-700',
        'bg-orange-50 text-orange-700',
        'bg-rose-50 text-rose-700',
        'bg-stone-100 text-stone-700',
    ];
    $categoryKey = $category->slug ?: $category->name;
    $placeholderTint = $placeholderTints[abs(crc32($categoryKey)) % count($placeholderTints)];
@endphp

<button
    type="button"
    wire:key="card-{{ $product->id }}"
    data-product-card="{{ $product->id }}"
    @if($available)
        wire:click="{{ $hasOptions ? 'openProduct' : 'addDirectly' }}({{ $product->id }})"
    @else
        disabled
    @endif
    class="group flex flex-col overflow-hidden rounded-2xl border border-[var(--hairline)] text-left transition hover:border-[var(--accent)] disabled:cursor-not-allowed disabled:opacity-55"
>
    @if($product->image_url)
        <img
            data-product-image
            src="{{ $product->image_url }}"
            alt="{{ $product->name }}"
            loading="lazy"
            decoding="async"
            class="aspect-square w-full object-cover"
        >
    @else
        <div data-product-placeholder aria-hidden="true" class="grid aspect-square w-full place-items-center {{ $placeholderTint }}">
            <svg class="size-9 opacity-70" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M7 9h10l-1 10H8L7 9Z"/>
                <path d="M9 9V7a3 3 0 0 1 6 0v2"/>
                <path d="M10 13h4"/>
            </svg>
        </div>
    @endif

    <div class="flex flex-1 flex-col p-3">
        {{-- Fixed title and description regions keep a row of cards level. --}}
        <p class="clamp-2 min-h-[34px] text-[13.5px] font-semibold leading-snug">{{ $product->name }}</p>

        <div class="mt-1 min-h-[32px]">
            @if($product->description)
                <p class="clamp-2 text-[12px] leading-snug text-[var(--muted)]">{{ $product->description }}</p>
            @endif
        </div>

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