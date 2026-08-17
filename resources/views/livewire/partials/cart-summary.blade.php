{{--
    Coupon field and the three money lines, shared by the desktop aside and the
    mobile sheet. Both are in the DOM at once, so every id is scoped.

    Subtotal, discount and total are always shown together: the courier collects
    what this says, and a bare discounted total reads as a mistake.
--}}
@php
    $hasDiscount = $totals['discount'] > 0;
    // The checkout takes no new codes: the coupon was entered in the cart and
    // lives in the cart session value, so here it is only shown or removed.
    $canApply = $canApply ?? true;
    $couponError = $couponError ?? null;
    $couponNotice = $couponNotice ?? null;

    // Measured against the subtotal, same figure the server enforces at submit.
    $minimumOrderAmount = (float) config('cart.minimum_order_amount', 5.00);
    $remainingForMinimumOrder = round(max(0.0, $minimumOrderAmount - (float) $totals['subtotal']), 2);
@endphp

@if($canApply || $appliedCoupon)
<div class="mb-3">
    @if($appliedCoupon && $hasDiscount)
        <div class="flex items-center justify-between rounded-lg bg-[var(--accent-light)] px-3 py-2">
            <span class="price text-[12.5px] font-semibold text-[var(--accent-text)]">Κουπόνι {{ $appliedCoupon }}</span>
            <button
                type="button"
                wire:click="removeCoupon"
                class="text-[12px] font-medium text-[var(--ink-soft)] underline underline-offset-2"
            >Αφαίρεση</button>
        </div>
    @elseif($canApply)
        <div class="flex gap-2">
            <label for="coupon-{{ $scope }}" class="sr-only">Κωδικός κουπονιού</label>
            <input
                id="coupon-{{ $scope }}"
                type="text"
                wire:model="couponInput"
                wire:keydown.enter.prevent="applyCoupon"
                placeholder="Κωδικός κουπονιού"
                autocomplete="off"
                autocapitalize="characters"
                class="min-w-0 flex-1 rounded-lg border border-[var(--hairline)] bg-white px-3 py-2 text-[13px] uppercase placeholder:normal-case placeholder:text-[var(--muted)] focus:border-[var(--accent)] focus:outline-none"
            >
            <button
                type="button"
                wire:click="applyCoupon"
                class="shrink-0 rounded-lg border border-[var(--hairline)] px-3 py-2 text-[13px] font-semibold text-[var(--ink-soft)] transition active:scale-95"
            >Εφαρμογή</button>
        </div>
    @endif

    @if($couponError)
        <p class="mt-1.5 text-[12px] font-medium text-red-600">{{ $couponError }}</p>
    @elseif($couponNotice)
        <p class="mt-1.5 text-[12px] font-medium text-amber-700">{{ $appliedCoupon }}: {{ $couponNotice }}</p>
    @endif
</div>
@endif

<div class="mb-3 space-y-1">
    <div class="flex items-baseline justify-between">
        <span class="text-[13px] font-medium text-[var(--ink-soft)]">Υποσύνολο</span>
        <span class="price text-[13px] font-semibold">{{ number_format($totals['subtotal'], 2, ',', '.') }} €</span>
    </div>

    @if($hasDiscount)
        <div class="flex items-baseline justify-between text-emerald-700">
            <span class="text-[13px] font-medium">Έκπτωση ({{ $appliedCoupon }})</span>
            <span class="price text-[13px] font-semibold">−{{ number_format($totals['discount'], 2, ',', '.') }} €</span>
        </div>
    @endif

    <div class="flex items-baseline justify-between pt-0.5">
        <span class="text-[13px] font-semibold">Σύνολο</span>
        <span class="price font-display {{ $scope === 'mobile' ? 'text-2xl' : 'text-xl' }} font-extrabold" style="color: var(--accent)">{{ number_format($totals['total'], 2, ',', '.') }} €</span>
    </div>

    @if($remainingForMinimumOrder > 0)
        <p class="pt-0.5 text-[11.5px] font-semibold text-amber-700">
            Χρειάζονται ακόμη {{ number_format($remainingForMinimumOrder, 2, ',', '.') }} € για την ελάχιστη παραγγελία.
        </p>
    @else
        <p class="pt-0.5 text-[11.5px] font-medium text-[var(--ink-soft)]">
            Ελάχιστη παραγγελία {{ number_format($minimumOrderAmount, 2, ',', '.') }} €
        </p>
    @endif
</div>
