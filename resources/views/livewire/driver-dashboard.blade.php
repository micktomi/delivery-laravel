@php
    $notificationOrderIds = $activeOrder
        ? [$activeOrder->getKey()]
        : $availableOrders->modelKeys();

    /* Presentation only: the three courier steps the state machine owns, in
       order, so the strip under the order number can mark where we are. */
    $deliverySteps = [
        \App\Enums\DeliveryStatus::Assigned,
        \App\Enums\DeliveryStatus::PickedUp,
        \App\Enums\DeliveryStatus::OutForDelivery,
    ];
@endphp

<main
    wire:poll.15s
    x-data="driverOrderNotifications({{ $driver->getKey() }})"
    class="min-h-dvh bg-stone-100 text-stone-950 {{ $activeOrder ? 'pb-32' : 'pb-8' }}"
>
    <span
        wire:key="driver-notification-orders-{{ $notificationOrderIds === [] ? 'none' : implode('-', $notificationOrderIds) }}"
        x-init="syncOrders(@js($notificationOrderIds))"
        class="hidden"
        aria-hidden="true"
    ></span>

    {{-- ══ HEADER ══ Rare actions live up here, out of the thumb zone on purpose. --}}
    <header class="sticky top-0 z-20 bg-stone-900 text-white">
        <div class="mx-auto flex max-w-lg items-center justify-between gap-3 px-4 py-2.5">
            <div class="min-w-0">
                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-stone-400">Οδηγός</p>
                <h1 class="truncate font-display text-lg font-extrabold leading-tight tracking-tight">{{ $driver->name }}</h1>
            </div>
            <button
                wire:click="endShift"
                wire:loading.attr="disabled"
                wire:target="endShift"
                type="button"
                class="min-h-11 shrink-0 touch-manipulation rounded-xl border border-stone-600 px-3 text-xs font-bold text-stone-200 transition hover:bg-stone-800 disabled:opacity-60"
            >
                Λήξη βάρδιας
            </button>
        </div>

        {{-- Sound unlock on its own strip: the label is too long to share a
             360px row with the name and the shift button. Colours come from
             soundControlClass() in app.js. --}}
        <div class="mx-auto max-w-lg px-4 pb-2.5">
            <button
                type="button"
                x-on:click="enableSound()"
                x-bind:disabled="soundEnabled || soundUnavailable"
                x-bind:class="soundControlClass()"
                class="min-h-11 w-full touch-manipulation rounded-xl px-3 text-xs font-bold transition"
                aria-live="polite"
            >
                <span x-text="soundStatusLabel()">🔔 Ενεργοποίηση ήχου</span>
            </button>
        </div>
    </header>

    <div class="mx-auto max-w-lg px-3 py-3 sm:px-4">
        @error('driver')
            <div class="mb-3 rounded-xl border border-amber-300 bg-amber-100 px-4 py-3 text-sm font-bold leading-snug text-amber-950" role="alert">{{ $message }}</div>
        @enderror

        @if($activeOrder)
            @php
                $dialPhone = preg_replace('/[^\d+]/', '', $activeOrder->phone);
                $mapsUrl = 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($activeOrder->address);
                $currentStep = array_search($activeOrder->delivery_status, $deliverySteps, true);
            @endphp

            {{-- ══ ACTIVE DELIVERY ══ --}}
            <section
                data-driver-order-id="{{ $activeOrder->id }}"
                x-bind:class="orderHighlightClass({{ $activeOrder->id }})"
                class="rounded-2xl border border-stone-200 bg-white transition"
            >
                {{-- Number + where we are in the delivery --}}
                <div class="px-4 pt-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[11px] font-extrabold uppercase tracking-[0.15em] text-stone-500">Ενεργή διανομή</p>
                            <h2 class="mt-0.5 font-display text-4xl font-extrabold leading-none tabular-nums tracking-tight">#{{ str_pad($activeOrder->display_number, 3, '0', STR_PAD_LEFT) }}</h2>
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1">
                            <span class="rounded-md bg-stone-900 px-2 py-1 text-[11px] font-extrabold uppercase tracking-wide text-white">{{ $activeOrder->delivery_status->getLabel() }}</span>
                            <span
                                x-show="isHighlighted({{ $activeOrder->id }})"
                                x-cloak
                                class="rounded-md bg-amber-500 px-2 py-1 text-[11px] font-extrabold uppercase tracking-wide text-stone-950"
                            >Νέα ανάθεση</span>
                        </div>
                    </div>

                    <ol class="mt-3 grid grid-cols-3 gap-1" aria-label="Πρόοδος διανομής">
                        @foreach($deliverySteps as $i => $step)
                            <li class="min-w-0">
                                <div class="h-1.5 rounded-full {{ $currentStep !== false && $i <= $currentStep ? 'bg-stone-900' : 'bg-stone-200' }}"></div>
                                <p class="mt-1 truncate text-[10px] font-bold uppercase tracking-wide {{ $i === $currentStep ? 'text-stone-900' : 'text-stone-400' }}">{{ $step->getLabel() }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>

                {{-- Amount + payment method: the number read at the door --}}
                <div class="px-4 pt-4">
                    @include('livewire.partials.driver-payment-instruction', ['order' => $activeOrder])
                </div>

                {{-- Customer: name, phone, address in reading order, all tappable --}}
                <div class="px-4 pt-4">
                    <p class="break-words text-xl font-bold leading-tight">{{ $activeOrder->customer_name }}</p>
                    <a href="tel:{{ $dialPhone }}" class="mt-1 inline-block min-h-11 py-1.5 font-display text-2xl font-extrabold tabular-nums tracking-wide text-stone-900 underline decoration-stone-300 underline-offset-4">{{ $activeOrder->phone }}</a>
                    <p class="mt-1 break-words text-lg font-semibold leading-snug">{{ $activeOrder->address }}</p>
                    @if($activeOrder->floor_bell)
                        <p class="mt-0.5 break-words text-base font-medium text-stone-600">{{ $activeOrder->floor_bell }}</p>
                    @endif
                    @if($activeOrder->notes)
                        <p class="mt-3 break-words rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold leading-snug text-amber-900">
                            <span class="mr-1 text-[11px] font-extrabold uppercase tracking-wide text-amber-700">Οδηγίες</span>{{ $activeOrder->notes }}
                        </p>
                    @endif
                </div>

                {{-- Contact / navigate: equal secondary actions --}}
                <div class="grid grid-cols-2 gap-2 px-4 pt-4">
                    <a href="tel:{{ $dialPhone }}" class="flex min-h-14 touch-manipulation items-center justify-center gap-2 rounded-xl border-2 border-stone-300 bg-white px-3 text-base font-extrabold text-stone-900 transition active:bg-stone-100">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/></svg>
                        Κλήση
                    </a>
                    <a href="{{ $mapsUrl }}" target="_blank" rel="noopener noreferrer" class="flex min-h-14 touch-manipulation items-center justify-center gap-2 rounded-xl border-2 border-stone-300 bg-white px-3 text-base font-extrabold text-stone-900 transition active:bg-stone-100">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11 22 2l-9 19-2-8-8-2z"/></svg>
                        Πλοήγηση
                    </a>
                </div>

                {{-- Items: quantity and name only, that is all the courier checks --}}
                <div class="mx-4 mt-4 border-t border-stone-200 py-3">
                    <p class="text-[11px] font-extrabold uppercase tracking-wide text-stone-500">Είδη ({{ $activeOrder->items->sum('quantity') }})</p>
                    <ul class="mt-1.5 space-y-1">
                        @foreach($activeOrder->items as $item)
                            <li class="break-words text-base font-semibold leading-snug">{{ $item->quantity }}× {{ $item->product_name }}</li>
                        @endforeach
                    </ul>
                </div>
            </section>

            {{-- ══ PRIMARY ACTION ══ One button, pinned to the thumb zone. --}}
            <div class="fixed inset-x-0 bottom-0 z-20 border-t border-stone-200 bg-white/95 px-3 pt-3 backdrop-blur [padding-bottom:calc(0.75rem+env(safe-area-inset-bottom))] sm:px-4">
                <div class="mx-auto max-w-lg">
                    @if($activeOrder->delivery_status === \App\Enums\DeliveryStatus::Assigned)
                        <button wire:click="pickUp({{ $activeOrder->id }})" wire:loading.attr="disabled" wire:target="pickUp({{ $activeOrder->id }})" type="button" class="min-h-16 w-full touch-manipulation rounded-xl bg-amber-500 px-4 text-lg font-extrabold text-stone-950 transition hover:bg-amber-400 active:scale-[.98] disabled:opacity-60">Παρέλαβα την παραγγελία</button>
                    @elseif($activeOrder->delivery_status === \App\Enums\DeliveryStatus::PickedUp)
                        <button wire:click="outForDelivery({{ $activeOrder->id }})" wire:loading.attr="disabled" wire:target="outForDelivery({{ $activeOrder->id }})" type="button" class="min-h-16 w-full touch-manipulation rounded-xl bg-amber-500 px-4 text-lg font-extrabold text-stone-950 transition hover:bg-amber-400 active:scale-[.98] disabled:opacity-60">Ξεκινώ για παράδοση</button>
                    @elseif($activeOrder->delivery_status === \App\Enums\DeliveryStatus::OutForDelivery)
                        <button wire:click="deliver({{ $activeOrder->id }})" wire:loading.attr="disabled" wire:target="deliver({{ $activeOrder->id }})" type="button" class="min-h-16 w-full touch-manipulation rounded-xl bg-emerald-600 px-4 text-lg font-extrabold text-white transition hover:bg-emerald-700 active:scale-[.98] disabled:opacity-60">Παραδόθηκε</button>
                    @endif
                </div>
            </div>
        @else
            {{-- ══ READY FOR PICKUP ══ --}}
            <section>
                @if($availableOrders->isNotEmpty())
                    <div class="mb-2 flex items-baseline justify-between gap-3 px-1">
                        <h2 class="font-display text-lg font-extrabold tracking-tight">Έτοιμες για παραλαβή</h2>
                        <span class="text-sm font-bold tabular-nums text-stone-500">{{ $availableOrders->count() }}</span>
                    </div>
                    <p class="mb-3 px-1 text-xs font-medium text-stone-500">Στοιχεία πελάτη μετά την ανάληψη.</p>
                @endif

                <div class="space-y-3">
                    @forelse($availableOrders as $order)
                        <article
                            data-driver-order-id="{{ $order->id }}"
                            x-bind:class="orderHighlightClass({{ $order->id }})"
                            class="rounded-2xl border border-stone-200 bg-white p-4 transition"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-display text-3xl font-extrabold leading-none tabular-nums tracking-tight">#{{ str_pad($order->display_number, 3, '0', STR_PAD_LEFT) }}</p>
                                    <p class="mt-1.5 text-sm font-semibold text-stone-500">{{ $order->placed_at->format('H:i') }} · {{ $order->items->sum('quantity') }} είδη</p>
                                </div>
                                <span
                                    x-show="isHighlighted({{ $order->id }})"
                                    x-cloak
                                    class="shrink-0 rounded-md bg-amber-500 px-2 py-1 text-[11px] font-extrabold uppercase tracking-wide text-stone-950"
                                >Νέα παραγγελία</span>
                            </div>

                            <div class="mt-3">
                                @include('livewire.partials.driver-payment-instruction', ['order' => $order])
                            </div>

                            <button wire:click="claim({{ $order->id }})" wire:loading.attr="disabled" wire:target="claim({{ $order->id }})" type="button" class="mt-3 min-h-14 w-full touch-manipulation rounded-xl bg-stone-900 px-4 text-base font-extrabold text-white transition hover:bg-stone-700 active:scale-[.98] disabled:opacity-60">Ανάληψη παραγγελίας</button>
                        </article>
                    @empty
                        <div class="rounded-2xl border-2 border-dashed border-stone-300 px-5 py-16 text-center">
                            <p class="text-lg font-bold text-stone-500">Καμία παραγγελία προς παραλαβή</p>
                        </div>
                    @endforelse
                </div>
            </section>
        @endif
    </div>
</main>
