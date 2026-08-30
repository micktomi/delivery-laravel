<div class="min-h-screen bg-gray-50">

{{-- ══ HEADER ══ --}}
<header class="sticky top-0 z-10 bg-white border-b px-4 py-3 flex items-center gap-3">
    <a href="/" class="p-1 -ml-1 text-gray-500">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
        </svg>
    </a>
    <h1 class="font-black text-lg">Παραγγελία</h1>
</header>

{{-- ══ CONFIRMATION ══ --}}
@if($confirmedOrderNumber !== null)
    <div class="flex flex-col items-center justify-center min-h-[80vh] px-6 text-center">
        <div class="text-7xl mb-6">✅</div>
        <h2 class="text-2xl font-black mb-2">Η παραγγελία ελήφθη!</h2>
        <p class="text-gray-500 text-base mb-8">Θα σας παραδοθεί σύντομα.</p>

        <div class="w-full max-w-xs rounded-3xl border-4 py-6 px-8 mb-8"
            style="border-color: var(--accent); background: var(--accent-light);">
            <div class="text-sm font-bold uppercase tracking-widest mb-2" style="color: var(--accent-text)">
                Αριθμός παραγγελίας
            </div>
            <div class="font-black" style="font-size: 4rem; line-height: 1; color: var(--accent)">
                #{{ str_pad($confirmedOrderNumber, 3, '0', STR_PAD_LEFT) }}
            </div>
        </div>

        @if($droppedCouponCode)
            <div class="w-full max-w-xs mb-6 rounded-2xl border-2 border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
                Ο κωδικός {{ $droppedCouponCode }} δεν ίσχυε πλέον τη στιγμή της υποβολής.
                Η παραγγελία καταχωρίστηκε κανονικά, χωρίς την έκπτωση.
            </div>
        @endif

        <a href="{{ route('order.track', $confirmedOrderToken) }}"
            class="w-full max-w-xs block py-4 text-white font-black text-xl rounded-2xl text-center active:scale-95 transition shadow-lg mb-3"
            style="background: var(--accent);">
            Παρακολούθηση παραγγελίας →
        </a>

        <a href="/"
            class="w-full max-w-xs block py-4 font-bold text-base rounded-2xl text-center active:scale-95 transition border-2 border-gray-200 text-gray-500">
            Νέα παραγγελία
        </a>
    </div>

@else

<div class="mx-auto w-full max-w-4xl px-4">

{{-- ══ CHECKOUT / CART PROBLEMS ══ --}}
@if($errors->has('checkout') || $errors->has('cart'))
    <div class="pt-4">
        <div class="rounded-2xl border-2 border-red-200 bg-red-50 px-4 py-3 text-sm font-semibold text-red-700">
            {{ $errors->first('checkout') ?: $errors->first('cart') }}
        </div>
    </div>
@endif
@if(! $isAcceptingOrders && ! $errors->has('checkout'))
    <div class="pt-4">
        <div class="rounded-2xl border-2 border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
            {{ $closedStoreMessage }}
        </div>
    </div>
@endif
@if($unavailableProductNotice)
    <div class="pt-4" data-unavailable-product-notice>
        <p
            class="rounded-2xl border-2 border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900"
            role="status"
        >{{ $unavailableProductNotice }}</p>
    </div>
@endif


{{-- ══ CART SUMMARY ══ --}}
<div class="pt-4 pb-1">
    <h2 class="font-bold text-gray-500 text-sm uppercase tracking-wider mb-2.5">Σύνοψη</h2>
    <div class="bg-white rounded-2xl shadow-sm divide-y divide-gray-100 overflow-hidden">
        @foreach($cart as $item)
            <div class="px-4 py-3">
                <div class="flex justify-between items-baseline font-semibold text-base">
                    <span>{{ $item['quantity'] }}× {{ $item['product_name'] }}</span>
                    <span style="color: var(--accent)">{{ number_format($item['line_total'], 2) }}€</span>
                </div>
                @if(!empty($item['selected_options']))
                    <div class="text-sm text-gray-400 mt-0.5 leading-snug">
                        {{ \App\Services\OptionsPresenter::format($item['selected_options']) }}
                    </div>
                @endif
                @if(!empty($item['notes']))
                    <div class="text-sm mt-0.5" style="color: var(--accent-text)">📝 {{ $item['notes'] }}</div>
                @endif
            </div>
        @endforeach
        {{-- Subtotal, discount and total: the same partial the cart uses, with
             no code field — the coupon is entered there, not here. --}}
        <div class="px-4 pt-3 pb-0.5 bg-gray-50">
            @include('livewire.partials.cart-summary', ['scope' => 'checkout', 'canApply' => false])
        </div>
    </div>
</div>

{{-- ══ FORM ══ --}}
<form wire:submit="submit" class="space-y-4 py-3 pb-32">

    {{-- Delivery details --}}
    <div class="bg-white rounded-2xl shadow-sm p-4 space-y-3">
        <h2 class="font-black text-base text-gray-800">Στοιχεία παράδοσης</h2>

        {{-- Name --}}
        <div>
            <label class="block text-sm font-bold text-gray-600 mb-1.5">Όνομα *</label>
            <input type="text" wire:model="customer_name"
                placeholder="Όνομα Επώνυμο"
                inputmode="text" autocomplete="name"
                class="w-full border-2 rounded-xl px-4 py-3.5 text-base bg-gray-50 focus:outline-none focus:border-amber-400 transition
                    @error('customer_name') border-red-400 @else border-gray-100 @enderror">
            @error('customer_name')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Phone --}}
        <div>
            <label class="block text-sm font-bold text-gray-600 mb-1.5">Τηλέφωνο *</label>
            <input type="tel" wire:model="phone"
                placeholder="69xxxxxxxx"
                inputmode="numeric" autocomplete="tel"
                class="w-full border-2 rounded-xl px-4 py-3.5 text-base bg-gray-50 focus:outline-none focus:border-amber-400 transition
                    @error('phone') border-red-400 @else border-gray-100 @enderror">
            @error('phone')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Email --}}
        <div>
            <label class="block text-sm font-bold text-gray-600 mb-1.5">
                Email
                <span class="font-normal text-gray-400">(προαιρετικό)</span>
            </label>

            <input
                type="email"
                wire:model="customer_email"
                autocomplete="email"
                placeholder="name@example.com"
                class="w-full rounded-xl border-gray-300 focus:border-orange-500 focus:ring-orange-500"
            >

            @error('customer_email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- Address --}}
        <div>
            <label class="block text-sm font-bold text-gray-600 mb-1.5">Διεύθυνση *</label>
            <input type="text" wire:model="address"
                placeholder="Οδός αριθμός, Πόλη"
                autocomplete="street-address"
                class="w-full border-2 rounded-xl px-4 py-3.5 text-base bg-gray-50 focus:outline-none focus:border-amber-400 transition
                    @error('address') border-red-400 @else border-gray-100 @enderror">
            @error('address')
                <p class="text-red-500 text-sm mt-1">{{ $message }}</p>
            @enderror
        </div>

        {{-- Floor / Bell --}}
        <div>
            <label class="block text-sm font-bold text-gray-600 mb-1.5">Όροφος / Κουδούνι</label>
            <input type="text" wire:model="floor_bell"
                placeholder="π.χ. 2ος, κουδούνι Παπαδόπουλος"
                class="w-full border-2 border-gray-100 rounded-xl px-4 py-3.5 text-base bg-gray-50 focus:outline-none focus:border-amber-400 transition">
        </div>

        {{-- Notes --}}
        <div>
            <label class="block text-sm font-bold text-gray-600 mb-1.5">Σημειώσεις</label>
            <textarea wire:model="notes"
                placeholder="Οδηγίες παράδοσης κτλ."
                rows="2"
                class="w-full border-2 border-gray-100 rounded-xl px-4 py-3 text-base bg-gray-50 focus:outline-none focus:border-amber-400 transition resize-none">
            </textarea>
        </div>
    </div>

    {{-- Payment --}}
    <div class="bg-white rounded-2xl shadow-sm p-4">
        <h2 class="font-black text-base text-gray-800 mb-3">Τρόπος πληρωμής</h2>
        <div class="grid grid-cols-2 gap-3">
            @foreach($paymentMethods as $method)
                <label class="relative flex flex-col items-center justify-center p-4 rounded-2xl border-3 cursor-pointer transition
                    {{ $payment_method === $method->value
                        ? 'border-amber-400 bg-amber-50 shadow-md'
                        : 'border-gray-200 bg-gray-50' }}"
                    style="{{ $payment_method === $method->value ? 'border-color: var(--accent);' : '' }}"
                    data-payment-method="{{ $method->value }}"
                    data-payment-selected="{{ $payment_method === $method->value ? 'true' : 'false' }}"
                >
                    <input type="radio"
                        wire:model.live="payment_method"
                        value="{{ $method->value }}"
                        @checked($payment_method === $method->value)
                        class="sr-only">
                    <span class="text-2xl mb-1">{{ $method->value === 'cash' ? '💵' : '💳' }}</span>
                    <span class="text-xs font-bold text-center leading-tight text-gray-700">{{ $method->getLabel() }}</span>
                    @if($payment_method === $method->value)
                        <span class="absolute top-2 right-2 w-5 h-5 rounded-full flex items-center justify-center"
                            style="background: var(--accent);">
                            <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/>
                            </svg>
                        </span>
                    @endif
                </label>
            @endforeach
        </div>
        <p
            class="mt-3 text-sm font-semibold text-gray-600"
            data-payment-summary="{{ $selectedPaymentMethod?->value }}"
        >Επιλογή: {{ $selectedPaymentMethod?->getLabel() }}</p>
        @error('payment_method')
            <p class="text-red-500 text-sm mt-2">{{ $message }}</p>
        @enderror
    </div>

</form>

{{-- ══ STICKY SUBMIT ══ --}}
<div class="fixed bottom-0 left-0 right-0 z-20 border-t bg-white px-4 pt-2 pb-3"
    style="padding-bottom: max(1rem, env(safe-area-inset-bottom));">
    <div class="mx-auto w-full max-w-4xl">
        <button
            type="button"
            wire:click="submit"
            @class([
                'w-full py-3.5 text-white font-black text-xl rounded-2xl shadow-lg transition active:scale-95',
                'opacity-60' => $checkoutDisabled,
            ])
            style="background: var(--accent);"
            wire:loading.attr="disabled"
            wire:loading.class="opacity-60"
            @disabled($checkoutDisabled)
        >
            <span wire:loading.remove>{{ $isAcceptingOrders ? 'Υποβολή παραγγελίας' : 'Το κατάστημα είναι κλειστό' }}</span>
            <span wire:loading class="flex items-center justify-center gap-2">
                <svg class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                Επεξεργασία...
            </span>
        </button>
    </div>
</div>

</div>

@endif

</div>
