<main wire:poll.15s class="min-h-dvh bg-[var(--sunken)] pb-8">
    <header class="border-b border-[var(--hairline)] bg-white px-4 py-4">
        <div class="mx-auto flex max-w-lg items-center justify-between gap-4">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--accent-text)]">Οδηγός</p>
                <h1 class="font-display mt-0.5 text-lg font-extrabold tracking-tight">Γεια σου, {{ $driver->name }}</h1>
            </div>
            <button wire:click="endShift" type="button" class="rounded-lg border border-[var(--hairline)] px-3 py-2 text-xs font-semibold text-[var(--ink-soft)]">Λήξη βάρδιας</button>
        </div>
    </header>

    <div class="mx-auto max-w-lg space-y-5 px-4 py-5">
        @error('driver')
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900">{{ $message }}</div>
        @enderror

        @if($activeOrder)
            @php
                $dialPhone = preg_replace('/[^\d+]/', '', $activeOrder->phone);
                $mapsUrl = 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($activeOrder->address);
            @endphp
            <section class="overflow-hidden rounded-3xl border border-[var(--hairline)] bg-white shadow-[0_18px_40px_-28px_rgb(28_18_6_/_0.35)]">
                <div class="border-b border-[var(--hairline)] bg-[var(--accent-light)] px-5 py-4">
                    <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--accent-text)]">Ενεργή διανομή</p>
                    <div class="mt-1 flex items-baseline justify-between gap-3">
                        <h2 class="font-display text-2xl font-extrabold">#{{ str_pad($activeOrder->display_number, 3, '0', STR_PAD_LEFT) }}</h2>
                        <span class="text-xs font-bold text-[var(--accent-text)]">{{ $activeOrder->delivery_status->getLabel() }}</span>
                    </div>
                </div>

                <div class="space-y-4 px-5 py-5">
                    <div>
                        <p class="font-semibold">{{ $activeOrder->customer_name }}</p>
                        <a href="tel:{{ $dialPhone }}" class="mt-0.5 block text-sm text-[var(--ink-soft)]">{{ $activeOrder->phone }}</a>
                        <p class="mt-2 text-sm leading-relaxed text-[var(--ink-soft)]">{{ $activeOrder->address }}</p>
                        @if($activeOrder->floor_bell)
                            <p class="mt-0.5 text-sm text-[var(--ink-soft)]">{{ $activeOrder->floor_bell }}</p>
                        @endif
                        @if($activeOrder->notes)
                            <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm leading-snug text-amber-900">Οδηγίες: {{ $activeOrder->notes }}</p>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <a href="tel:{{ $dialPhone }}" class="flex min-h-11 items-center justify-center rounded-xl border border-[var(--accent)] px-3 py-2 text-sm font-semibold" style="color: var(--accent);">
                            📞 Κλήση
                        </a>
                        <a href="{{ $mapsUrl }}" target="_blank" rel="noopener noreferrer" class="flex min-h-11 items-center justify-center rounded-xl px-3 py-2 text-sm font-semibold text-white" style="background: var(--accent);">
                            📍 Πλοήγηση
                        </a>
                    </div>

                    <div class="border-y border-[var(--hairline)] py-3 text-sm">
                        @foreach($activeOrder->items as $item)
                            <p class="py-0.5">{{ $item->quantity }}× {{ $item->product_name }}</p>
                        @endforeach
                    </div>

                    <div class="flex items-baseline justify-between">
                        <span class="text-sm font-semibold">Προς είσπραξη</span>
                        <span class="price font-display text-xl font-extrabold" style="color: var(--accent)">{{ number_format($activeOrder->total, 2, ',', '.') }} €</span>
                    </div>

                    @if($activeOrder->delivery_status === \App\Enums\DeliveryStatus::Assigned)
                        <button wire:click="pickUp({{ $activeOrder->id }})" type="button" class="w-full rounded-xl px-4 py-3.5 text-sm font-semibold text-white" style="background: var(--accent);">Παρέλαβα την παραγγελία</button>
                    @elseif($activeOrder->delivery_status === \App\Enums\DeliveryStatus::PickedUp)
                        <button wire:click="outForDelivery({{ $activeOrder->id }})" type="button" class="w-full rounded-xl px-4 py-3.5 text-sm font-semibold text-white" style="background: var(--accent);">Ξεκινώ για παράδοση</button>
                    @elseif($activeOrder->delivery_status === \App\Enums\DeliveryStatus::OutForDelivery)
                        <button wire:click="deliver({{ $activeOrder->id }})" type="button" class="w-full rounded-xl px-4 py-3.5 text-sm font-semibold text-white" style="background: var(--accent);">Παραδόθηκε</button>
                    @endif
                </div>
            </section>
        @else
            <section>
                <div class="mb-3 flex items-baseline justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[0.15em] text-[var(--accent-text)]">Διαθέσιμες</p>
                        <h2 class="font-display mt-0.5 text-xl font-extrabold tracking-tight">Έτοιμες παραγγελίες</h2>
                    </div>
                    <span class="price text-sm font-semibold text-[var(--muted)]">{{ $availableOrders->count() }}</span>
                </div>

                <div class="space-y-3">
                    @forelse($availableOrders as $order)
                        <article class="rounded-2xl border border-[var(--hairline)] bg-white p-4 shadow-sm">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="font-display text-xl font-extrabold">#{{ str_pad($order->display_number, 3, '0', STR_PAD_LEFT) }}</p>
                                    <p class="mt-1 font-semibold">{{ $order->customer_name }}</p>
                                    <p class="mt-0.5 text-sm leading-relaxed text-[var(--ink-soft)]">{{ $order->address }}</p>
                                </div>
                                <span class="price shrink-0 text-sm font-bold">{{ number_format($order->total, 2, ',', '.') }} €</span>
                            </div>
                            <button wire:click="claim({{ $order->id }})" type="button" class="mt-4 w-full rounded-xl border border-[var(--accent)] px-4 py-3 text-sm font-semibold" style="color: var(--accent);">Ανάληψη παραγγελίας</button>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-[var(--hairline)] bg-white px-5 py-10 text-center">
                            <p class="font-semibold">Δεν υπάρχουν έτοιμες παραγγελίες</p>
                            <p class="mt-1 text-sm text-[var(--ink-soft)]">Η λίστα ανανεώνεται αυτόματα.</p>
                        </div>
                    @endforelse
                </div>
            </section>
        @endif
    </div>
</main>
