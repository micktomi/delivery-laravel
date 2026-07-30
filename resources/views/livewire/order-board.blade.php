<div
    wire:poll.10s
    {{-- A phone scrolls the page; only from the tablet up is the board pinned
         to the viewport with each column scrolling inside itself. --}}
    class="min-h-dvh flex flex-col bg-gray-100 select-none md:h-dvh md:overflow-hidden"
    x-data="{
        alerting:    false,
        audioReady:  false,
        audioCtx:    null,
        alertTimer:  null,
        currentMaxId: {{ $currentMaxId }},

        /* ── Audio unlock (D1): call once on first tap ── */
        unlockAudio() {
            if (this.audioReady) return;
            this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            /* play+pause a silent buffer to satisfy browser gesture requirement */
            const buf = this.audioCtx.createBuffer(1, 1, 22050);
            const src = this.audioCtx.createBufferSource();
            src.buffer = buf;
            src.connect(this.audioCtx.destination);
            src.start();
            this.audioReady = true;
        },

        playBeep() {
            if (!this.audioReady || !this.audioCtx) return;
            if (this.audioCtx.state === 'suspended') this.audioCtx.resume();
            const osc  = this.audioCtx.createOscillator();
            const gain = this.audioCtx.createGain();
            osc.connect(gain);
            gain.connect(this.audioCtx.destination);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, this.audioCtx.currentTime);
            gain.gain.setValueAtTime(0.6, this.audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, this.audioCtx.currentTime + 0.7);
            osc.start();
            osc.stop(this.audioCtx.currentTime + 0.7);
        },

        startAlert(maxId) {
            this.currentMaxId = maxId;
            if (this.alerting) return;
            this.alerting = true;
            this.playBeep();
            this.alertTimer = setInterval(() => { if (this.alerting) this.playBeep(); }, 2500);
        },

        stopAlert() {
            this.alerting = false;
            clearInterval(this.alertTimer);
            this.alertTimer = null;
            $wire.acknowledge(this.currentMaxId);
        },

        /* ── Mobile status tabs ──
           The selected column is stored on <body> rather than in this
           component, because the 10s poll morphs every attribute inside the
           Livewire root back to what the server rendered. See app.css. */
        selectStatus(status) {
            document.body.dataset.kitchenStatus = status;
        }
    }"
    x-on:new-orders.window="startAlert($event.detail.maxId)"
>

{{-- ══ ALERT BANNER ══ --}}
<div
    x-show="alerting"
    class="shrink-0 bg-red-600 text-white px-4 sm:px-6 py-3 sm:py-4 flex flex-wrap items-center justify-between gap-3 z-50"
    x-cloak
>
    <div class="min-w-0 font-black text-xl sm:text-2xl tracking-wide animate-pulse">
        🔔 ΝΕΑ ΠΑΡΑΓΓΕΛΙΑ!
    </div>
    <button
        x-on:click="stopAlert()"
        class="shrink-0 bg-white text-red-700 font-black text-base sm:text-lg px-5 sm:px-8 py-3 rounded-xl hover:bg-red-50 active:scale-95 transition"
    >
        ✓ ACKNOWLEDGE
    </button>
</div>

{{-- ══ CONFLICT / ERROR BANNER ══ --}}
@error('board')
    <div class="shrink-0 bg-amber-500 text-amber-950 px-4 sm:px-6 py-3 font-bold text-base sm:text-lg break-words">
        ⚠️ {{ $message }}
    </div>
@enderror

{{-- ══ HEADER ══ --}}
<header class="shrink-0 bg-gray-900 text-white px-3 sm:px-5 py-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
    <div class="flex flex-wrap items-center gap-x-3 sm:gap-x-4 gap-y-1 min-w-0">
        <span class="font-black text-lg sm:text-xl tracking-tight">☕ {{ config('app.name') }}</span>
        <span class="text-gray-400 text-xs sm:text-sm">{{ now()->format('d/m/Y · H:i') }}</span>
        <a href="/kitchen/history" class="shrink-0 bg-gray-700 hover:bg-gray-600 text-white px-3 py-1.5 rounded-lg text-sm font-bold transition flex items-center gap-1">
            📜 Ιστορικό
        </a>
    </div>

    {{-- ── Audio unlock button (D1) ── --}}
    <button
        x-on:click="unlockAudio()"
        x-bind:class="audioReady
            ? 'bg-green-700 text-green-100 cursor-default'
            : 'bg-yellow-500 text-gray-900 hover:bg-yellow-400 animate-pulse'"
        class="shrink-0 flex items-center gap-2 px-3 sm:px-4 py-2 rounded-lg font-bold text-xs sm:text-sm transition"
        x-bind:disabled="audioReady"
    >
        <span x-show="!audioReady">🔇 Ενεργοποίηση ήχου</span>
        <span x-show="audioReady" x-cloak>🔊 Ήχος ενεργός</span>
    </button>
</header>

{{-- ══ MOBILE STATUS TABS ══
     Only rendered as a control under md; the CSS in app.css pairs each tab
     with the column of the same status. --}}
<nav
    class="md:hidden shrink-0 sticky top-0 z-30 grid grid-cols-4 gap-px bg-gray-300 border-b border-gray-300"
    aria-label="Επιλογή στήλης"
>
    @foreach($columns as $col)
        @php $tabStatus = $col['status']; @endphp
        <button
            type="button"
            data-kitchen-tab="{{ $tabStatus->value }}"
            x-on:click="selectStatus('{{ $tabStatus->value }}')"
            class="min-w-0 px-1 py-2 font-black text-[10px] sm:text-xs leading-tight uppercase tracking-tight transition
                @if($tabStatus->value === 'nea')           bg-yellow-200 text-yellow-900
                @elseif($tabStatus->value === 'preparing') bg-blue-200   text-blue-900
                @elseif($tabStatus->value === 'ready')     bg-green-200  text-green-900
                @elseif($tabStatus->value === 'out')       bg-purple-200 text-purple-900
                @endif
            "
        >
            <span class="block break-words">{{ $tabStatus->getLabel() }}</span>
            <span class="block font-bold opacity-60">({{ $col['orders']->count() }})</span>
        </button>
    @endforeach
</nav>

{{-- ══ KANBAN ══
     One tab-selected column on a phone, 2 on a tablet, the original 4 from xl.
     Nothing here constrains height until md: on a phone the column, its label
     and its list are all natural height, so the page scrolls as one document
     and no hidden column can reserve space. The hairlines come from gap-px
     over a gray backdrop rather than divide-x, so they stay correct once the
     columns wrap onto two rows. --}}
<div class="md:flex-1 md:overflow-hidden">
    <div class="grid gap-px bg-gray-300
        grid-cols-1
        md:h-full md:grid-cols-2 md:grid-rows-2
        xl:grid-cols-4 xl:grid-rows-1">
        @foreach($columns as $col)
            @php $status = $col['status']; $orders = $col['orders']; @endphp
            {{-- data-kitchen-column sits on the outermost wrapper so hiding it
                 takes the label, the list and the empty state with it. --}}
            <div
                data-kitchen-column="{{ $status->value }}"
                class="flex flex-col min-w-0 bg-gray-100
                    md:h-full md:min-h-0 md:overflow-hidden"
            >

                {{-- Column label --}}
                <div class="md:shrink-0 py-3 px-2 font-black text-base text-center uppercase tracking-widest break-words
                    @if($status->value === 'nea')       bg-yellow-200 text-yellow-900
                    @elseif($status->value === 'preparing') bg-blue-200   text-blue-900
                    @elseif($status->value === 'ready')     bg-green-200  text-green-900
                    @elseif($status->value === 'out')       bg-purple-200 text-purple-900
                    @endif
                ">
                    {{ $status->getLabel() }}
                    @if($orders->count())
                        <span class="ml-1 font-normal opacity-60 text-sm">({{ $orders->count() }})</span>
                    @endif
                </div>

                {{-- Order cards --}}
                <div class="p-2 space-y-3 md:flex-1 md:overflow-y-auto">
                    @forelse($orders as $order)
                        <div class="w-full min-w-0 bg-white rounded-2xl shadow-md border-l-[6px]
                            @if($status->value === 'nea')       border-yellow-400
                            @elseif($status->value === 'preparing') border-blue-400
                            @elseif($status->value === 'ready')     border-green-400
                            @elseif($status->value === 'out')       border-purple-400
                            @endif
                            p-4"
                        >
                            {{-- Order number + time --}}
                            <div class="flex items-baseline justify-between gap-2 mb-2">
                                <span class="min-w-0 font-black text-3xl leading-none">
                                    #{{ str_pad($order->display_number, 3, '0', STR_PAD_LEFT) }}
                                </span>
                                <span class="shrink-0 text-sm text-gray-400 font-medium">{{ $order->placed_at->format('H:i') }}</span>
                            </div>

                            {{-- Customer --}}
                            <div class="font-bold text-base leading-snug break-words">{{ $order->customer_name }}</div>
                            <div class="text-sm text-gray-600 break-words">{{ $order->phone }}</div>
                            <div class="text-sm text-gray-700 mt-0.5 leading-tight break-words">{{ $order->address }}</div>
                            @if($order->floor_bell)
                                <div class="text-sm text-gray-500 break-words">{{ $order->floor_bell }}</div>
                            @endif

                            {{-- Payment badge --}}
                            <div class="mt-2 mb-3">
                                <span class="inline-block max-w-full text-sm font-bold px-3 py-1 rounded-full break-words
                                    {{ $order->payment_method->value === 'cash'
                                        ? 'bg-green-100 text-green-800'
                                        : 'bg-blue-100 text-blue-800' }}">
                                    {{ $order->payment_method->getLabel() }}
                                </span>
                            </div>

                            {{-- Items --}}
                            <div class="space-y-2 border-t border-gray-100 pt-2">
                                @foreach($order->items as $item)
                                    <div class="min-w-0">
                                        <div class="font-semibold text-base break-words">
                                            {{ $item->quantity }}× {{ $item->product_name }}
                                        </div>
                                        @if(!empty($item->selected_options))
                                            <div class="text-sm text-gray-500 pl-3 leading-snug break-words">
                                                · {{ collect($item->selected_options)->pluck('value')->implode(' · ') }}
                                            </div>
                                        @endif
                                        @if($item->notes)
                                            <div class="text-sm text-amber-700 pl-3 break-words">📝 {{ $item->notes }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            @if($order->notes)
                                <div class="text-sm text-amber-800 mt-2 bg-amber-50 rounded-lg px-3 py-2 break-words">
                                    📝 {{ $order->notes }}
                                </div>
                            @endif

                            {{-- Amount to collect.
                                 This board is the handover screen, and there is
                                 no printed ticket: it is the only place the
                                 courier can read what to take. Shown as three
                                 lines, because a discounted total alone reads
                                 as a pricing mistake. --}}
                            <div class="mt-3 border-t-2 border-gray-200 pt-2">
                                <div class="flex items-baseline justify-between gap-2 text-sm text-gray-500">
                                    <span class="min-w-0 font-semibold">Υποσύνολο</span>
                                    <span class="shrink-0 font-bold">{{ number_format($order->subtotal, 2) }}€</span>
                                </div>
                                @if($order->hasDiscount())
                                    <div class="flex items-baseline justify-between gap-2 text-sm text-emerald-700">
                                        <span class="min-w-0 font-semibold break-words">Έκπτωση ({{ $order->coupon_code }})</span>
                                        <span class="shrink-0 font-bold">−{{ number_format($order->discount_amount, 2) }}€</span>
                                    </div>
                                @endif
                                <div class="flex items-baseline justify-between gap-2 mt-1">
                                    <span class="min-w-0 font-black text-base">Προς είσπραξη</span>
                                    <span class="shrink-0 font-black text-2xl">{{ number_format($order->total, 2) }}€</span>
                                </div>
                            </div>

                            {{-- Advance button --}}
                            @if($status->nextStatus() !== null)
                                <button
                                    wire:click="advance({{ $order->id }}, '{{ $status->value }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="advance({{ $order->id }}, '{{ $status->value }}')"
                                    class="mt-3 w-full px-2 py-3 text-base font-black rounded-xl transition active:scale-95 break-words
                                        @if($status->value === 'nea')       bg-blue-500   hover:bg-blue-600   text-white
                                        @elseif($status->value === 'preparing') bg-green-500  hover:bg-green-600  text-white
                                        @elseif($status->value === 'ready')     bg-purple-500 hover:bg-purple-600 text-white
                                        @endif"
                                    x-on:click="if (alerting) stopAlert()"
                                >
                                    → {{ $status->nextStatus()->getLabel() }}
                                </button>
                            @endif

                            {{-- Cancel --}}
                            <button
                                wire:click="cancel({{ $order->id }})"
                                wire:loading.attr="disabled"
                                wire:target="cancel({{ $order->id }})"
                                wire:confirm="Ακύρωση της παραγγελίας #{{ str_pad($order->display_number, 3, '0', STR_PAD_LEFT) }};"
                                class="mt-2 w-full py-2 text-sm font-bold rounded-xl border-2 border-gray-200 text-gray-400 hover:border-red-300 hover:text-red-600 transition"
                            >
                                Ακύρωση
                            </button>
                        </div>
                    @empty
                        <div class="flex items-center justify-center h-20 md:h-32 text-gray-300 text-2xl">—</div>
                    @endforelse
                </div>

            </div>
        @endforeach
    </div>
</div>

</div>
