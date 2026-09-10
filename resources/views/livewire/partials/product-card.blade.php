{{-- Canonical storefront product: a list row on phones, a horizontal card from sm up.
     Same DOM at every width; only spacing and the border change. --}}
@php
    $available = $product->is_available;
    $hasOptions = $product->optionGroups->isNotEmpty();
    /* The add control is a real 44px target on every screen, next to the
       price, never on top of the photo. Accent is reserved for it. */
    $addActionShellClasses = 'grid size-11 shrink-0 place-items-center rounded-xl transition';
    $idleAddActionClasses = $addActionShellClasses.' bg-[var(--accent)] text-white group-hover:bg-[var(--accent-hover)]';
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
    class="group relative flex w-full min-w-0 max-w-full items-center gap-3 border-b border-[var(--hairline)] py-3 text-left transition disabled:cursor-not-allowed disabled:opacity-55 disabled:grayscale sm:items-stretch sm:rounded-2xl sm:border sm:bg-white sm:p-3 sm:hover:border-[var(--muted)]"
>
    <div
        data-product-media
        class="relative size-18 shrink-0 overflow-hidden rounded-xl bg-[var(--sunken)] text-[var(--muted)] sm:size-24 lg:size-28"
    >
        <div data-product-placeholder aria-hidden="true" class="absolute inset-0 grid place-items-center">
            <svg class="size-7 opacity-60 sm:size-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <path d="M7 9h10l-1 10H8L7 9Z"/>
                <path d="M9 9V7a3 3 0 0 1 6 0v2"/>
                <path d="M10 13h4"/>
            </svg>
        </div>

        @if($product->image_url)
        <img
            data-product-image
            src="{{ $product->image_url }}"
            alt=""
            loading="lazy"
            decoding="async"
            onerror="this.hidden = true"
            class="absolute inset-0 size-full object-cover"
        >
        @endif
    </div>

    <div class="flex min-w-0 flex-1 flex-col self-stretch">
        <p class="clamp-2 text-[15px] font-semibold leading-snug sm:text-base">{{ $product->name }}</p>

        @if($product->description)
            <p class="clamp-2 text-[13px] mt-0.5 leading-snug text-[var(--muted)]">{{ $product->description }}</p>
        @endif

        <div class="mt-auto flex items-center justify-between gap-2 pt-2">
            <span class="price text-base font-bold">
                {{ number_format($product->base_price, 2, ',', '.') }} €
            </span>

            @if(! $available)
                <span class="text-xs font-semibold text-[var(--ink-soft)]">Μη διαθέσιμο</span>
            @elseif($hasOptions)
                <span data-product-action="customize" class="{{ $idleAddActionClasses }}">
                    <svg class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M10 5v10M5 10h10"/></svg>
                </span>
            @else
                <span
                    data-product-action="add"
                    x-bind:class="addButtonClass({{ $product->id }})"
                    class="{{ $addActionShellClasses }}"
                >
                    <svg x-show="notAdded({{ $product->id }})" class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M10 5v10M5 10h10"/></svg>
                    <svg x-cloak x-show="isAdded({{ $product->id }})" class="size-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 10.5l4 4 8-9"/></svg>
                </span>
            @endif
        </div>
    </div>
</button>
