<div
    wire:poll.10s
    class="h-screen flex flex-col bg-gray-100 overflow-hidden select-none"
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
        }
    }"
    x-on:new-orders.window="startAlert($event.detail.maxId)"
>

{{-- ══ ALERT BANNER ══ --}}
<div
    x-show="alerting"
    class="shrink-0 bg-red-600 text-white px-6 py-4 flex items-center justify-between z-50"
    x-cloak
>
    <div class="font-black text-2xl tracking-wide animate-pulse">
        🔔 ΝΕΑ ΠΑΡΑΓΓΕΛΙΑ!
    </div>
    <button
        x-on:click="stopAlert()"
        class="bg-white text-red-700 font-black text-lg px-8 py-3 rounded-xl hover:bg-red-50 active:scale-95 transition"
    >
        ✓ ACKNOWLEDGE
    </button>
</div>

{{-- ══ CONFLICT / ERROR BANNER ══ --}}
@error('board')
    <div class="shrink-0 bg-amber-500 text-amber-950 px-6 py-3 font-bold text-lg">
        ⚠️ {{ $message }}
    </div>
@enderror

{{-- ══ HEADER ══ --}}
<header class="shrink-0 bg-gray-900 text-white px-5 py-3 flex items-center justify-between">
    <div class="flex items-center gap-4">
        <span class="font-black text-xl tracking-tight">☕ {{ config('app.name') }}</span>
        <span class="text-gray-400 text-sm">{{ now()->format('d/m/Y · H:i') }}</span>
        <a href="/kitchen/history" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1.5 rounded-lg text-sm font-bold transition flex items-center gap-1">
            📜 Ιστορικό
        </a>
    </div>

    {{-- ── Audio unlock button (D1) ── --}}
    <button
        x-on:click="unlockAudio()"
        x-bind:class="audioReady
            ? 'bg-green-700 text-green-100 cursor-default'
            : 'bg-yellow-500 text-gray-900 hover:bg-yellow-400 animate-pulse'"
        class="flex items-center gap-2 px-4 py-2 rounded-lg font-bold text-sm transition"
        x-bind:disabled="audioReady"
    >
        <span x-show="!audioReady">🔇 Ενεργοποίηση ήχου</span>
        <span x-show="audioReady" x-cloak>🔊 Ήχος ενεργός</span>
    </button>
</header>

{{-- ══ KANBAN ══ --}}
<div class="flex-1 overflow-hidden">
    <div class="h-full grid grid-cols-4 divide-x divide-gray-300">
        @foreach($columns as $col)
            @php $status = $col['status']; $orders = $col['orders']; @endphp
            <div class="flex flex-col h-full overflow-hidden">

                {{-- Column label --}}
                <div class="shrink-0 py-3 font-black text-base text-center uppercase tracking-widest
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
                <div class="flex-1 overflow-y-auto p-2 space-y-3">
                    @forelse($orders as $order)
                        <div class="bg-white rounded-2xl shadow-md border-l-[6px]
                            @if($status->value === 'nea')       border-yellow-400
                            @elseif($status->value === 'preparing') border-blue-400
                            @elseif($status->value === 'ready')     border-green-400
                            @elseif($status->value === 'out')       border-purple-400
                            @endif
                            p-4"
                        >
                            {{-- Order number + time --}}
                            <div class="flex items-baseline justify-between mb-2">
                                <span class="font-black text-3xl leading-none">
                                    #{{ str_pad($order->display_number, 3, '0', STR_PAD_LEFT) }}
                                </span>
                                <span class="text-sm text-gray-400 font-medium">{{ $order->placed_at->format('H:i') }}</span>
                            </div>

                            {{-- Customer --}}
                            <div class="font-bold text-base leading-snug">{{ $order->customer_name }}</div>
                            <div class="text-sm text-gray-600">{{ $order->phone }}</div>
                            <div class="text-sm text-gray-700 mt-0.5 leading-tight">{{ $order->address }}</div>
                            @if($order->floor_bell)
                                <div class="text-sm text-gray-500">{{ $order->floor_bell }}</div>
                            @endif

                            {{-- Payment badge --}}
                            <div class="mt-2 mb-3">
                                <span class="inline-block text-sm font-bold px-3 py-1 rounded-full
                                    {{ $order->payment_method->value === 'cash'
                                        ? 'bg-green-100 text-green-800'
                                        : 'bg-blue-100 text-blue-800' }}">
                                    {{ $order->payment_method->getLabel() }}
                                </span>
                            </div>

                            {{-- Items --}}
                            <div class="space-y-2 border-t border-gray-100 pt-2">
                                @foreach($order->items as $item)
                                    <div>
                                        <div class="font-semibold text-base">
                                            {{ $item->quantity }}× {{ $item->product_name }}
                                        </div>
                                        @if(!empty($item->selected_options))
                                            <div class="text-sm text-gray-500 pl-3 leading-snug">
                                                · {{ collect($item->selected_options)->pluck('value')->implode(' · ') }}
                                            </div>
                                        @endif
                                        @if($item->notes)
                                            <div class="text-sm text-amber-700 pl-3">📝 {{ $item->notes }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            @if($order->notes)
                                <div class="text-sm text-amber-800 mt-2 bg-amber-50 rounded-lg px-3 py-2">
                                    📝 {{ $order->notes }}
                                </div>
                            @endif

                            {{-- Advance button --}}
                            @if($status->nextStatus() !== null)
                                <button
                                    wire:click="advance({{ $order->id }}, '{{ $status->value }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="advance({{ $order->id }}, '{{ $status->value }}')"
                                    class="mt-3 w-full py-3 text-base font-black rounded-xl transition active:scale-95
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
                        <div class="flex items-center justify-center h-32 text-gray-300 text-2xl">—</div>
                    @endforelse
                </div>

            </div>
        @endforeach
    </div>
</div>

</div>
