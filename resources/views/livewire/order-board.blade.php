@php
    /* Presentation only. One hue per stage the kitchen owns (ΝΕΑ, ΕΤΟΙΜΑΖΕΤΑΙ),
       green once the order is ready, neutral once it has left the shop. Every
       other colour on the board is the warm-grey neutral scale, red for the
       new-order alarm and for an order that is running late. */
    $theme = [
        'nea'       => ['bar' => 'border-amber-500',   'chip' => 'bg-amber-100 text-amber-900',     'button' => 'bg-amber-500 text-stone-950 hover:bg-amber-400'],
        'preparing' => ['bar' => 'border-sky-600',     'chip' => 'bg-sky-100 text-sky-900',         'button' => 'bg-sky-600 text-white hover:bg-sky-700'],
        'ready'     => ['bar' => 'border-emerald-600', 'chip' => 'bg-emerald-100 text-emerald-900', 'button' => ''],
        'out'       => ['bar' => 'border-stone-400',   'chip' => 'bg-stone-200 text-stone-700',     'button' => ''],
    ];

    /* Minutes since the order was placed. Only the two stages the kitchen is
       responsible for get the warning colours; a ready order waiting for a
       driver is not the kitchen's delay. */
    $warnAfter = 10;
    $lateAfter = 20;

    /* Ready and Out are advanced by the driver app; TransitionOrderStatus
       refuses those steps from the board, so no button is drawn for them. */
    $primaryVerb = [
        'nea'       => 'Έναρξη',
        'preparing' => 'Έτοιμο',
    ];
    $waitingLabel = [
        'ready' => 'Αναμονή για οδηγό',
        'out'   => 'Σε διανομή',
    ];
@endphp

<div
    wire:poll.10s
    {{-- A phone scrolls the page; only from the tablet up is the board pinned
         to the viewport with the card area scrolling inside itself. --}}
    class="min-h-dvh flex flex-col bg-stone-100 text-stone-950 select-none md:h-dvh md:overflow-hidden"
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

        /* ── Status tabs ──
           The selected status is stored on <body> rather than in this
           component, because the 10s poll morphs every attribute inside the
           Livewire root back to what the server rendered. See app.css. */
        selectStatus(status) {
            document.body.dataset.kitchenStatus = status;
        }
    }"
    x-on:new-orders.window="startAlert($event.detail.maxId)"
>

{{-- ══ NEW ORDER ALARM ══ --}}
<div
    x-show="alerting"
    class="shrink-0 z-50 flex flex-wrap items-center justify-between gap-3 bg-red-600 px-4 py-3 text-white sm:px-6"
    x-cloak
>
    <div class="flex min-w-0 items-center gap-3">
        <span class="grid size-11 shrink-0 place-items-center rounded-full bg-white/15 animate-pulse" aria-hidden="true">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        </span>
        <span class="font-display text-xl font-extrabold uppercase tracking-wide sm:text-2xl">Νέα παραγγελία</span>
    </div>
    <button
        type="button"
        x-on:click="stopAlert()"
        class="min-h-12 shrink-0 touch-manipulation rounded-xl bg-white px-6 text-base font-extrabold text-red-700 transition hover:bg-red-50 active:scale-95 sm:px-8 sm:text-lg"
    >
        Ελήφθη
    </button>
</div>

{{-- ══ CONFLICT / ERROR BANNER ══ --}}
@error('board')
    <div class="shrink-0 border-b border-amber-300 bg-amber-100 px-4 py-3 text-base font-bold leading-snug text-amber-950 break-words sm:px-6" role="alert">
        {{ $message }}
    </div>
@enderror

{{-- ══ HEADER ══ --}}
<header class="shrink-0 bg-stone-900 text-white">
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-3 py-2.5 sm:px-5">
        <div class="flex min-w-0 items-baseline gap-x-3">
            <span class="font-display text-lg font-extrabold tracking-tight sm:text-xl">{{ config('app.name') }}</span>
            <span class="text-xs font-semibold uppercase tracking-[0.14em] text-stone-400">Κουζίνα</span>
            <span class="text-sm tabular-nums text-stone-400">{{ now()->format('H:i') }}</span>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <a
                href="{{ route('kitchen.availability') }}"
                class="flex min-h-11 items-center rounded-xl border border-stone-600 px-4 text-sm font-bold text-stone-100 transition hover:bg-stone-800 active:scale-95"
            >
                Διαθεσιμότητα
            </a>

            {{-- ── Audio unlock button (D1) ── --}}
            <button
                type="button"
                x-on:click="unlockAudio()"
                x-bind:class="audioReady
                    ? 'border-stone-600 text-stone-300 cursor-default'
                    : 'border-amber-500 bg-amber-500 text-stone-950 hover:bg-amber-400 animate-pulse'"
                class="flex min-h-11 shrink-0 touch-manipulation items-center gap-2 rounded-xl border px-4 text-sm font-bold transition"
                x-bind:disabled="audioReady"
            >
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H2v6h4l5 4V5z"/><path x-show="audioReady" d="M15.5 8.5a5 5 0 0 1 0 7M19 5a9 9 0 0 1 0 14"/><path x-show="!audioReady" d="m22 9-6 6M16 9l6 6"/></svg>
                <span x-show="!audioReady">Ενεργοποίηση ήχου</span>
                <span x-show="audioReady" x-cloak>Ήχος ενεργός</span>
            </button>
        </div>
    </div>
</header>

{{-- ══ STATUS TABS ══
     The board shows one status at a time on every screen size; app.css pairs
     each tab with the column of the same status through the body attribute. --}}
<nav
    class="shrink-0 sticky top-0 z-30 grid grid-cols-4 border-b border-stone-300 bg-stone-200"
    aria-label="Επιλογή κατάστασης"
>
    @foreach($columns as $col)
        @php $tabStatus = $col['status']; $tabCount = $col['orders']->count(); @endphp
        <button
            type="button"
            data-kitchen-tab="{{ $tabStatus->value }}"
            x-on:click="selectStatus('{{ $tabStatus->value }}')"
            class="flex min-h-14 min-w-0 touch-manipulation flex-col items-center justify-center gap-0.5 px-1 py-2 transition sm:min-h-16 sm:flex-row sm:gap-2"
        >
            <span class="block break-words font-display text-[11px] font-extrabold uppercase leading-tight tracking-wide sm:text-sm">{{ $tabStatus->getLabel() }}</span>
            <span
                data-kitchen-count
                class="grid min-w-6 place-items-center rounded-full px-1.5 text-xs font-extrabold tabular-nums leading-6 sm:min-w-7 sm:text-sm sm:leading-7
                    {{ $tabStatus->value === 'nea' && $tabCount > 0 ? 'bg-amber-500 text-stone-950' : ($tabCount > 0 ? 'bg-stone-900/10 text-current' : 'text-stone-400') }}"
                @if($tabStatus->value === 'nea') x-bind:class="alerting ? 'animate-pulse' : ''" @endif
            >{{ $tabCount }}</span>
        </button>
    @endforeach
</nav>

{{-- ══ BOARD ══
     Nothing here constrains height until md: on a phone every section is
     natural height so the page scrolls as one document. From the tablet up
     this area is the only thing that scrolls. --}}
<main class="md:flex-1 md:min-h-0 md:overflow-y-auto">
    @foreach($columns as $col)
        @php $status = $col['status']; $orders = $col['orders']; @endphp
        {{-- data-kitchen-column sits on the outermost wrapper so hiding it
             takes the heading, the grid and the empty state with it. --}}
        <div
            data-kitchen-column="{{ $status->value }}"
            wire:key="column-{{ $status->value }}"
            class="flex-col min-w-0 px-3 pb-6 pt-3 sm:px-4 md:pb-8 md:pt-4"
        >
            <h2 class="sr-only">{{ $status->getLabel() }} ({{ $orders->count() }})</h2>

            @if($orders->isEmpty())
                <div class="rounded-2xl border-2 border-dashed border-stone-300 py-10 text-center md:py-16">
                    <p class="text-lg font-bold text-stone-400">Καμία παραγγελία</p>
                </div>
            @else
                <div class="grid grid-cols-1 items-start gap-3 md:grid-cols-2 md:gap-4 lg:grid-cols-3">
                    @foreach($orders as $order)
                        @php
                            $minutes = max(0, (int) $order->placed_at->diffInMinutes(now()));
                            $elapsed = $minutes < 60
                                ? $minutes.'′'
                                : intdiv($minutes, 60).'ω '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'′';
                            $kitchenStage = in_array($status->value, ['nea', 'preparing'], true);
                            $ageClass = ! $kitchenStage
                                ? 'bg-stone-100 text-stone-600'
                                : ($minutes >= $lateAfter
                                    ? 'bg-red-600 text-white'
                                    : ($minutes >= $warnAfter ? 'bg-amber-100 text-amber-900' : 'bg-stone-100 text-stone-800'));
                        @endphp
                        <article
                            wire:key="order-{{ $order->id }}"
                            data-kitchen-card
                            class="w-full min-w-0 bg-white flex flex-col rounded-2xl border border-stone-200 border-t-4 shadow-sm {{ $theme[$status->value]['bar'] }}"
                        >
                            {{-- Order number · elapsed time --}}
                            <div class="flex items-start justify-between gap-3 px-4 pt-3">
                                <div class="min-w-0">
                                    <div class="font-display text-3xl font-extrabold leading-none tabular-nums tracking-tight">
                                        #{{ str_pad($order->display_number, 3, '0', STR_PAD_LEFT) }}
                                    </div>
                                    <span class="mt-1.5 inline-block rounded-md px-1.5 py-0.5 text-[11px] font-extrabold uppercase tracking-wide {{ $theme[$status->value]['chip'] }}">
                                        {{ $status->getLabel() }}
                                    </span>
                                </div>
                                <div class="shrink-0 text-right">
                                    <div
                                        class="inline-flex min-h-9 items-center rounded-lg px-2.5 font-display text-xl font-extrabold tabular-nums leading-none {{ $ageClass }}"
                                        title="{{ $minutes }} λεπτά από την παραγγελία"
                                    >
                                        {{ $elapsed }}
                                    </div>
                                    <div class="mt-1 text-xs font-semibold tabular-nums text-stone-400">{{ $order->placed_at->format('H:i') }}</div>
                                </div>
                            </div>

                            {{-- Compact handover identity; logistics belong on the driver screen. --}}
                            <div class="mt-2 break-words px-4 text-base font-semibold leading-tight text-stone-700">{{ $order->customer_name }}</div>

                            {{-- Preparation details --}}
                            <div class="mx-4 mt-3 border-t border-stone-200">
                                @foreach($order->items as $item)
                                    <div class="min-w-0 border-b border-stone-100 py-2.5 last:border-0">
                                        <div class="break-words text-lg font-bold leading-snug">
                                            {{ $item->quantity }}× {{ $item->product_name }}
                                        </div>
                                        @if(!empty($item->selected_options))
                                            <div class="mt-0.5 break-words text-sm font-medium leading-snug text-stone-500">
                                                {{ \App\Services\OptionsPresenter::format($item->selected_options) }}
                                            </div>
                                        @endif
                                        @if($item->notes)
                                            <div class="mt-1 break-words text-sm font-bold leading-snug text-amber-800">{{ $item->notes }}</div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            @if($order->notes)
                                <div class="mx-4 mt-3 break-words rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-semibold leading-snug text-amber-900">
                                    <span class="mr-1 text-[11px] font-extrabold uppercase tracking-wide text-amber-700">Σημείωση</span>
                                    {{ $order->notes }}
                                </div>
                            @endif

                            {{-- Actions: one primary action per card; cancel stays low-emphasis. --}}
                            <div class="mt-auto flex items-stretch gap-2 px-4 pb-4 pt-4">
                                <button
                                    type="button"
                                    wire:click="cancel({{ $order->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="cancel({{ $order->id }})"
                                    wire:confirm="Ακύρωση της παραγγελίας #{{ str_pad($order->display_number, 3, '0', STR_PAD_LEFT) }};"
                                    class="min-h-14 shrink-0 touch-manipulation rounded-xl border border-stone-300 px-4 text-sm font-bold text-stone-500 transition hover:border-red-300 hover:bg-red-50 hover:text-red-700 disabled:opacity-50"
                                >
                                    Ακύρωση
                                </button>

                                @if(isset($primaryVerb[$status->value]))
                                    <button
                                        type="button"
                                        wire:click="advance({{ $order->id }}, '{{ $status->value }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="advance({{ $order->id }}, '{{ $status->value }}')"
                                        x-on:click="if (alerting) stopAlert()"
                                        class="min-h-14 min-w-0 grow basis-0 touch-manipulation break-words rounded-xl px-3 text-lg font-extrabold leading-tight transition active:scale-[.98] disabled:opacity-50 {{ $theme[$status->value]['button'] }}"
                                    >
                                        {{ $primaryVerb[$status->value] }}
                                        <span class="block text-[11px] font-bold uppercase tracking-wide opacity-75">→ {{ $status->nextStatus()->getLabel() }}</span>
                                    </button>
                                @else
                                    <div class="flex min-h-14 min-w-0 grow basis-0 items-center justify-center rounded-xl bg-stone-100 px-3 text-center text-sm font-bold leading-tight text-stone-500">
                                        {{ $waitingLabel[$status->value] }}
                                    </div>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach
</main>

</div>
