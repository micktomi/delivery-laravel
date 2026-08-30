{{-- Canonical storefront product: compact row on phones, existing card from sm up. --}}
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
    class="group relative flex w-full items-center gap-3 overflow-visible border-b border-[var(--hairline)] py-3 text-left transition hover:border-[var(--accent)] disabled:cursor-not-allowed disabled:opacity-55 disabled:grayscale sm:flex-col sm:items-stretch sm:gap-0 sm:overflow-hidden sm:rounded-2xl sm:border sm:py-0 lg:rounded-[1.15rem] lg:transition-[border-color,box-shadow,transform] lg:duration-200 lg:hover:-translate-y-0.5 lg:hover:shadow-[0_16px_30px_-22px_rgb(28_18_6_/_0.45)]"
>
    @if($product->image_url)
        <img
            data-product-image
            src="{{ $product->image_url }}"
            alt="{{ $product->name }}"
            loading="lazy"
            decoding="async"
            class="order-2 size-[76px] shrink-0 rounded-2xl object-cover sm:order-none sm:aspect-square sm:h-auto sm:w-full sm:rounded-none"
        >
    @else
        <div data-product-placeholder aria-hidden="true" class="order-2 grid size-[76px] shrink-0 place-items-center rounded-2xl {{ $placeholderTint }} sm:order-none sm:aspect-square sm:h-auto sm:w-full sm:rounded-none">
            <svg class="size-7 opacity-70 sm:size-9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M7 9h10l-1 10H8L7 9Z"/>
                <path d="M9 9V7a3 3 0 0 1 6 0v2"/>
                <path d="M10 13h4"/>
            </svg>
        </div>
    @endif

    <div class="order-1 flex min-w-0 flex-1 flex-col pr-1 sm:order-none sm:p-3 lg:p-[1.125rem]">
        <p class="clamp-2 text-[15px] font-semibold leading-snug sm:min-h-[34px] sm:text-[13.5px] lg:text-[14px]">{{ $product->name }}</p>

        @if($product->description)
            <div class="mt-1 sm:min-h-[32px] lg:mt-1.5">
                <p class="clamp-2 text-[12px] leading-snug text-[var(--muted)] lg:text-[12.5px]">{{ $product->description }}</p>
            </div>
        @else
            {{-- Preserve equal-height cards above mobile without reserving row space. --}}
            <div aria-hidden="true" class="hidden sm:mt-1 sm:block sm:min-h-[32px] lg:mt-1.5"></div>
        @endif

        <div class="mt-2 flex items-center justify-between gap-2 lg:mt-3">
            <span class="price text-[15px] font-bold lg:text-base">
                {{ number_format($product->base_price, 2, ',', '.') }} €
            </span>

            @if(! $available)
                <span class="text-[11px] font-semibold text-[var(--ink-soft)]">Μη διαθέσιμο</span>
            @elseif($hasOptions)
                <span class="absolute bottom-1 right-0 grid size-7 shrink-0 place-items-center rounded-full bg-[var(--accent)] text-white shadow-[0_3px_8px_rgb(180_83_9_/_0.35)] transition group-hover:bg-[var(--accent-hover)] sm:static sm:size-8 sm:rounded-lg sm:border sm:border-[var(--hairline)] sm:bg-transparent sm:text-[var(--ink-soft)] sm:shadow-none sm:group-hover:border-[var(--accent)] sm:group-hover:bg-transparent sm:group-hover:text-[var(--accent)] lg:size-9 lg:rounded-xl lg:group-hover:shadow-sm">
                    <svg class="size-4 sm:hidden" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M10 5v10M5 10h10"/></svg>
                    <svg class="hidden size-4 sm:block" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 5l5 5-5 5"/></svg>
                </span>
            @else
                <span
                    x-bind:class="addButtonClass({{ $product->id }})"
                    class="absolute bottom-1 right-0 grid size-7 shrink-0 place-items-center rounded-full shadow-[0_3px_8px_rgb(180_83_9_/_0.35)] transition sm:static sm:size-8 sm:rounded-lg sm:shadow-none lg:size-9 lg:rounded-xl lg:shadow-sm lg:group-hover:shadow-md"
                >
                    <svg x-show="notAdded({{ $product->id }})" class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M10 5v10M5 10h10"/></svg>
                    <svg x-cloak x-show="isAdded({{ $product->id }})" class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10.5l4 4 8-9"/></svg>
                </span>
            @endif
        </div>
    </div>
</button>
