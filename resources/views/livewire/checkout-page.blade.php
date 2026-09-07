@php
    /* Presentation only. Field chrome is shared so every input reads the same;
       the bindings, ids and autocomplete hints are what the browser and the
       component rely on and are unchanged. */
    $field = 'block min-h-14 w-full rounded-xl border bg-white px-4 text-base text-stone-950 transition placeholder:text-stone-400 focus:border-[var(--accent)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]/25';
    $fieldOk = $field.' border-stone-300';
    $fieldError = $field.' border-red-500';
    $label = 'mb-1.5 block text-sm font-semibold text-stone-800';
    $optional = '<span class="font-normal text-stone-500">(προαιρετικό)</span>';
    $cartCount = array_sum(array_column($cart, 'quantity'));

    /* Customer-facing consequence of each method, shown under the list. */
    $paymentHint = [
        'cash' => 'Πληρώνεις μετρητά στον διανομέα κατά την παράδοση.',
        'pos_courier' => 'Πληρώνεις με κάρτα στο POS του διανομέα κατά την παράδοση.',
        'viva' => 'Θα μεταφερθείς στη Viva Wallet για ασφαλή online πληρωμή.',
    ];
@endphp

<div class="min-h-dvh bg-stone-50">

{{-- ══ HEADER ══ --}}
<header class="sticky top-0 z-10 border-b border-stone-200 bg-white">
    <div class="mx-auto flex max-w-lg items-center gap-1 py-2 pl-2 pr-4 lg:max-w-5xl">
        <a href="/" aria-label="Πίσω στο μενού" class="grid size-11 shrink-0 place-items-center rounded-xl text-stone-700 transition hover:bg-stone-100">
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m15 19-7-7 7-7"/></svg>
        </a>
        <h1 class="font-display text-lg font-extrabold tracking-tight">{{ $confirmedOrderNumber !== null ? 'Παραγγελία' : 'Ολοκλήρωση παραγγελίας' }}</h1>
    </div>
</header>

{{-- ══ CONFIRMATION ══ --}}
@if($confirmedOrderNumber !== null)
    <div class="mx-auto flex min-h-[calc(100dvh-3.75rem)] w-full max-w-sm flex-col justify-center px-4 py-10 text-center">
        <p class="text-sm font-semibold text-stone-600">Η παραγγελία σου καταχωρήθηκε</p>
        <p class="mt-3 font-display text-[4.5rem] font-extrabold leading-none tabular-nums tracking-tight">
            #{{ str_pad($confirmedOrderNumber, 3, '0', STR_PAD_LEFT) }}
        </p>
        <p class="mt-4 text-base leading-relaxed text-stone-600">Θα σε ενημερώνουμε για την πορεία της στη σελίδα παρακολούθησης.</p>

        @if($droppedCouponCode)
            <div class="mt-6 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-left text-sm font-medium leading-snug text-amber-950">
                Ο κωδικός {{ $droppedCouponCode }} δεν ίσχυε πλέον τη στιγμή της υποβολής.
                Η παραγγελία καταχωρίστηκε κανονικά, χωρίς την έκπτωση.
            </div>
        @endif

        <a href="{{ route('order.track', $confirmedOrderToken) }}"
            class="mt-8 flex min-h-14 w-full items-center justify-center rounded-xl px-4 text-base font-bold text-white transition active:scale-[.98]"
            style="background: var(--accent);">
            Παρακολούθηση παραγγελίας
        </a>

        <a href="/" class="mt-2 flex min-h-11 items-center justify-center rounded-xl px-4 text-sm font-semibold text-stone-600 transition hover:text-stone-950">
            Νέα παραγγελία
        </a>
    </div>

@else

<div class="mx-auto w-full max-w-lg px-4 pb-32 lg:grid lg:max-w-5xl lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start lg:gap-8">

{{-- ══ SUMMARY ══ First in the DOM: on a phone you check what you ordered
     before you fill in where it goes; from lg it is the sticky right column. --}}
<aside class="pt-4 lg:sticky lg:top-20 lg:order-2 lg:pt-6">
    <div class="rounded-2xl border border-stone-200 bg-white">
        <div class="flex items-baseline justify-between border-b border-stone-200 px-4 py-3">
            <h2 class="font-display text-base font-extrabold tracking-tight">Η παραγγελία σου</h2>
            <a href="/" class="text-sm font-semibold text-stone-600 underline underline-offset-2 hover:text-stone-950">{{ $cartCount }} {{ $cartCount === 1 ? 'προϊόν' : 'προϊόντα' }} · Αλλαγή</a>
        </div>
        <div class="px-4">
            @foreach($cart as $item)
                <div class="border-b border-stone-100 py-3 last:border-0">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="min-w-0 text-sm font-semibold leading-snug">{{ $item['quantity'] }}× {{ $item['product_name'] }}</span>
                        <span class="price shrink-0 text-sm font-bold">{{ number_format($item['line_total'], 2, ',', '.') }} €</span>
                    </div>
                    @if(!empty($item['selected_options']))
                        <div class="mt-0.5 text-[13px] leading-snug text-stone-500">
                            {{ \App\Services\OptionsPresenter::format($item['selected_options']) }}
                        </div>
                    @endif
                    @if(!empty($item['notes']))
                        <div class="mt-0.5 text-[13px] leading-snug text-amber-800">{{ $item['notes'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>
        {{-- Subtotal, discount and total: the same partial the cart uses, with
             no code field — the coupon is entered there, not here. --}}
        <div class="rounded-b-2xl border-t border-stone-200 bg-stone-50 px-4 pb-1 pt-3">
            @include('livewire.partials.cart-summary', ['scope' => 'checkout', 'canApply' => false])
        </div>
    </div>
</aside>

<div class="lg:order-1 lg:pt-6">

{{-- ══ CHECKOUT / CART PROBLEMS ══ --}}
@if($errors->has('checkout') || $errors->has('cart'))
    <div class="pt-4 lg:pt-0">
        <div class="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm font-semibold leading-snug text-red-800" role="alert">
            {{ $errors->first('checkout') ?: $errors->first('cart') }}
        </div>
    </div>
@endif
@if(! $isAcceptingOrders && ! $errors->has('checkout'))
    <div class="pt-4 lg:pt-0">
        <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold leading-snug text-amber-950">
            {{ $closedStoreMessage }}
        </div>
    </div>
@endif
@if($unavailableProductNotice)
    <div class="pt-4 lg:pt-0" data-unavailable-product-notice>
        <p
            class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold leading-snug text-amber-950"
            role="status"
        >{{ $unavailableProductNotice }}</p>
    </div>
@endif

{{-- ══ FORM ══ --}}
<form wire:submit="submit" class="space-y-4 pt-4 lg:pt-0">

    {{-- Delivery details --}}
    <section class="rounded-2xl border border-stone-200 bg-white p-4 sm:p-5">
        <h2 class="font-display text-lg font-extrabold tracking-tight">Στοιχεία παράδοσης</h2>

        <div class="mt-4 space-y-4">
            {{-- Name --}}
            <div>
                <label for="customer-name" class="{{ $label }}">Όνομα</label>
                <input id="customer-name" type="text" wire:model="customer_name"
                    placeholder="Όνομα Επώνυμο"
                    inputmode="text" autocomplete="name"
                    class="{{ $errors->has('customer_name') ? $fieldError : $fieldOk }}">
                @error('customer_name')
                    <p class="mt-1.5 text-sm font-medium text-red-700">{{ $message }}</p>
                @enderror
            </div>

            {{-- Phone --}}
            <div>
                <label for="customer-phone" class="{{ $label }}">Τηλέφωνο</label>
                <input id="customer-phone" type="tel" wire:model="phone"
                    placeholder="69xxxxxxxx"
                    inputmode="numeric" autocomplete="tel"
                    class="{{ $errors->has('phone') ? $fieldError : $fieldOk }}">
                @error('phone')
                    <p class="mt-1.5 text-sm font-medium text-red-700">{{ $message }}</p>
                @enderror
            </div>

            {{-- Email --}}
            <div>
                <label for="customer-email" class="{{ $label }}">Email {!! $optional !!}</label>
                <input
                    id="customer-email"
                    type="email"
                    wire:model="customer_email"
                    autocomplete="email"
                    inputmode="email"
                    placeholder="name@example.com"
                    class="{{ $errors->has('customer_email') ? $fieldError : $fieldOk }}"
                >
                @error('customer_email')
                    <p class="mt-1.5 text-sm font-medium text-red-700">{{ $message }}</p>
                @enderror
            </div>

            {{-- Address --}}
            <div>
                <label for="customer-address" class="{{ $label }}">Διεύθυνση</label>
                <input id="customer-address" type="text" wire:model="address"
                    placeholder="Οδός αριθμός, Πόλη"
                    autocomplete="street-address"
                    class="{{ $errors->has('address') ? $fieldError : $fieldOk }}">
                @error('address')
                    <p class="mt-1.5 text-sm font-medium text-red-700">{{ $message }}</p>
                @enderror
            </div>

            {{-- Floor / Bell --}}
            <div>
                <label for="customer-floor" class="{{ $label }}">Όροφος / Κουδούνι {!! $optional !!}</label>
                <input id="customer-floor" type="text" wire:model="floor_bell"
                    placeholder="π.χ. 2ος, κουδούνι Παπαδόπουλος"
                    class="{{ $fieldOk }}">
            </div>

            {{-- Notes --}}
            <div>
                <label for="customer-notes" class="{{ $label }}">Σημειώσεις {!! $optional !!}</label>
                <textarea id="customer-notes" wire:model="notes"
                    placeholder="Οδηγίες για τον διανομέα"
                    rows="2"
                    class="{{ $fieldOk }} resize-none py-3.5 leading-snug"></textarea>
            </div>
        </div>
    </section>

    {{-- Payment --}}
    <section class="rounded-2xl border border-stone-200 bg-white p-4 sm:p-5">
        <h2 class="font-display text-lg font-extrabold tracking-tight">Τρόπος πληρωμής</h2>
        <div class="mt-4 space-y-2" role="radiogroup" aria-label="Τρόπος πληρωμής">
            @foreach($paymentMethods as $method)
                @php $selected = $payment_method === $method->value; @endphp
                <label
                    class="flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border px-4 py-2.5 transition {{ $selected ? 'border-[var(--accent)] bg-[var(--accent-light)]' : 'border-stone-300 bg-white hover:border-stone-400' }}"
                    data-payment-method="{{ $method->value }}"
                    data-payment-selected="{{ $selected ? 'true' : 'false' }}"
                >
                    <input type="radio"
                        wire:model.live="payment_method"
                        value="{{ $method->value }}"
                        @checked($selected)
                        class="sr-only">
                    <span aria-hidden="true" class="grid size-5 shrink-0 place-items-center rounded-full border-2 {{ $selected ? 'border-[var(--accent)]' : 'border-stone-400' }}">
                        @if($selected)<span class="size-2.5 rounded-full" style="background: var(--accent);"></span>@endif
                    </span>
                    <svg class="size-6 shrink-0 {{ $selected ? 'text-[var(--accent-text)]' : 'text-stone-500' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        @if($method->value === 'cash')
                            <rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>
                        @else
                            <rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>
                        @endif
                    </svg>
                    <span class="text-base font-semibold {{ $selected ? 'text-[var(--accent-text)]' : 'text-stone-900' }}">{{ $method->getLabel() }}</span>
                </label>
            @endforeach
        </div>
        <p
            class="mt-3 text-sm leading-snug text-stone-600"
            data-payment-summary="{{ $selectedPaymentMethod?->value }}"
        >{{ $selectedPaymentMethod ? ($paymentHint[$selectedPaymentMethod->value] ?? $selectedPaymentMethod->getLabel()) : '' }}</p>
        @error('payment_method')
            <p class="mt-2 text-sm font-medium text-red-700">{{ $message }}</p>
        @enderror
    </section>

</form>
</div>
</div>

{{-- ══ STICKY SUBMIT ══ --}}
<div class="fixed bottom-0 left-0 right-0 z-20 border-t border-stone-200 bg-white/95 px-4 pt-3 backdrop-blur"
    style="padding-bottom: max(0.75rem, env(safe-area-inset-bottom));">
    <div class="mx-auto w-full max-w-lg lg:max-w-5xl">
        <button
            type="button"
            wire:click="submit"
            @class([
                'flex min-h-14 w-full items-center justify-center rounded-xl px-4 text-base font-bold text-white transition active:scale-[.98] lg:ml-auto lg:w-[360px]',
                'opacity-60' => $checkoutDisabled,
            ])
            style="background: var(--accent);"
            wire:loading.attr="disabled"
            wire:loading.class="opacity-60"
            @disabled($checkoutDisabled)
        >
            <span wire:loading.remove>{{ $isAcceptingOrders ? 'Υποβολή παραγγελίας' : 'Το κατάστημα είναι κλειστό' }}</span>
            <span wire:loading class="flex items-center justify-center gap-2">
                <svg class="animate-spin size-5" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Επεξεργασία…
            </span>
        </button>
    </div>
</div>

@endif

</div>
