@php
    /* Presentation only. OrderStatus labels are the kitchen's vocabulary;
       the customer sees these instead. Anything not mapped falls back to the
       enum label, so a new status can never render blank. */
    $customerLabel = [
        'nea' => 'Λάβαμε την παραγγελία',
        'preparing' => 'Ετοιμάζεται',
        'ready' => 'Έτοιμη',
        'out' => 'Στον δρόμο',
        'completed' => 'Παραδόθηκε',
    ];
    $labelFor = fn (\App\Enums\OrderStatus $status) => $customerLabel[$status->value] ?? $status->getLabel();
    $stepHint = [
        'nea' => 'Την είδαμε και θα ξεκινήσει σε λίγο.',
        'preparing' => 'Η κουζίνα την ετοιμάζει αυτή τη στιγμή.',
        'ready' => 'Περιμένει τον διανομέα για παραλαβή.',
        'out' => 'Ο διανομέας είναι καθ’ οδόν προς εσένα.',
        'completed' => 'Καλή απόλαυση!',
    ];
    $money = fn ($amount) => number_format((float) $amount, 2, ',', '.').' €';
    $currentStatus = $currentIndex >= 0 ? $steps[$currentIndex] : null;
@endphp

<div wire:poll.15s class="min-h-dvh bg-stone-50">

{{-- ══ HEADER ══ --}}
<header class="sticky top-0 z-10 border-b border-stone-200 bg-white">
    <div class="mx-auto flex max-w-lg items-center gap-1 py-2 pl-2 pr-4 lg:max-w-5xl">
        <a href="/" aria-label="Πίσω στο μενού" class="grid size-11 shrink-0 place-items-center rounded-xl text-stone-700 transition hover:bg-stone-100">
            <svg class="size-6" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="m15 19-7-7 7-7"/></svg>
        </a>
        <div class="min-w-0">
            <h1 class="font-display text-lg font-extrabold leading-tight tracking-tight">
                Παραγγελία #{{ str_pad($order->display_number, 3, '0', STR_PAD_LEFT) }}
            </h1>
            <p class="text-xs tabular-nums text-stone-500">{{ $order->placed_at->format('d/m/Y · H:i') }}</p>
        </div>
    </div>
</header>

<div class="mx-auto w-full max-w-lg px-4 py-4 lg:grid lg:max-w-5xl lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start lg:gap-8 lg:py-6">

{{-- ══ STATUS ══ --}}
<div class="space-y-4">
    @if(session('viva_status'))
        <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-semibold leading-snug text-amber-950">
            {{ session('viva_status') }}
        </div>
    @endif

    @if(session('viva_error'))
        <div class="rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm font-semibold leading-snug text-red-800">
            {{ session('viva_error') }}
        </div>
    @endif

    @if($order->payment_method === \App\Enums\PaymentMethod::Viva && $order->payment_status !== 'paid' && !$isCancelled)
        <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm leading-snug text-amber-950">
            <p class="font-bold">Αναμονή επιβεβαίωσης online πληρωμής.</p>
            <p class="mt-1">Η κουζίνα θα λάβει την παραγγελία μόνο μετά την επιβεβαίωση της Viva.</p>
            <a href="{{ route('viva.start', $order) }}"
                class="mt-3 flex min-h-11 w-full items-center justify-center rounded-xl px-4 text-sm font-bold text-white transition active:scale-[.98] sm:w-auto sm:px-5"
                style="background: var(--accent);">
                Συνέχεια στην πληρωμή
            </a>
        </div>
    @endif

    {{-- ══ CANCELLED ══ --}}
    @if($isCancelled)
        <div class="rounded-2xl border border-red-300 bg-red-50 px-5 py-5 text-red-900">
            <p class="font-display text-xl font-extrabold tracking-tight">Η παραγγελία ακυρώθηκε</p>
            <p class="mt-1 text-sm leading-snug">Επικοινώνησε μαζί μας για οποιαδήποτε διευκρίνιση.</p>
        </div>
    @else
        {{-- Current state, said once and large --}}
        <section class="rounded-2xl border border-stone-200 bg-white p-5">
            <p class="text-sm font-semibold text-stone-500">Τρέχουσα κατάσταση</p>
            <p class="mt-1 font-display text-2xl font-extrabold leading-tight tracking-tight">{{ $currentStatus ? $labelFor($currentStatus) : '' }}</p>
            @if($currentStatus && isset($stepHint[$currentStatus->value]))
                <p class="mt-1 text-base leading-snug text-stone-600">{{ $stepHint[$currentStatus->value] }}</p>
            @endif

            {{-- ══ PROGRESS STEPS ══ --}}
            <ol class="mt-5 border-t border-stone-200 pt-4" aria-label="Πορεία παραγγελίας">
                @foreach($steps as $i => $step)
                    @php
                        $done    = $i < $currentIndex;
                        $current = $i === $currentIndex;
                        $last    = $loop->last;
                    @endphp
                    <li class="flex gap-3">
                        <div class="flex w-8 shrink-0 flex-col items-center">
                            <div class="grid size-8 shrink-0 place-items-center rounded-full border-2
                                @if($done) border-stone-900 bg-stone-900 text-white
                                @elseif($current) border-[var(--accent)] bg-[var(--accent)] text-white
                                @else border-stone-300 bg-white text-stone-300
                                @endif"
                            >
                                @if($done)
                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>
                                @elseif($current)
                                    <span class="size-2.5 rounded-full bg-white"></span>
                                @else
                                    <span class="size-2 rounded-full bg-stone-300"></span>
                                @endif
                            </div>
                            @if(!$last)
                                <div class="my-1 w-0.5 min-h-6 flex-1 rounded-full {{ $done ? 'bg-stone-900' : 'bg-stone-200' }}"></div>
                            @endif
                        </div>
                        <div class="{{ $last ? 'pb-0' : 'pb-5' }} pt-1.5">
                            <p class="text-sm font-bold leading-none {{ $current ? 'text-stone-950' : ($done ? 'text-stone-700' : 'text-stone-400') }}">
                                {{ $labelFor($step) }}
                            </p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif
</div>

{{-- ══ DETAILS ══ --}}
<aside class="mt-4 space-y-4 lg:mt-0 lg:sticky lg:top-20">
    <section class="rounded-2xl border border-stone-200 bg-white px-4 py-3.5">
        <p class="text-base font-bold leading-tight">{{ $order->customer_name }}</p>
        <p class="mt-1 text-sm leading-snug text-stone-600">{{ $order->address }}</p>
        @if($order->floor_bell)
            <p class="text-sm leading-snug text-stone-500">{{ $order->floor_bell }}</p>
        @endif
        <div
            data-order-payment-method="{{ $order->payment_method->value }}"
            class="mt-3 flex items-baseline justify-between gap-3 border-t border-stone-200 pt-3"
        >
            <span class="text-sm text-stone-500">Πληρωμή</span>
            <span class="text-right text-sm font-semibold">{{ $order->payment_method->trackingLabel($order->payment_status) }}</span>
        </div>
    </section>

    <section class="rounded-2xl border border-stone-200 bg-white">
        <h2 class="border-b border-stone-200 px-4 py-3 font-display text-base font-extrabold tracking-tight">Σύνοψη παραγγελίας</h2>
        <div class="px-4">
            @foreach($order->items as $item)
                <div class="border-b border-stone-100 py-3 last:border-0">
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="min-w-0 text-sm font-semibold leading-snug">{{ $item->quantity }}× {{ $item->product_name }}</span>
                        <span class="price shrink-0 text-sm font-bold">{{ $money($item->line_total) }}</span>
                    </div>
                    @if(!empty($item->selected_options))
                        <div class="mt-0.5 text-[13px] leading-snug text-stone-500">
                            {{ \App\Services\OptionsPresenter::format($item->selected_options) }}
                        </div>
                    @endif
                    @if(!empty($item->notes))
                        <div class="mt-0.5 text-[13px] leading-snug text-amber-800">{{ $item->notes }}</div>
                    @endif
                </div>
            @endforeach
        </div>
        {{-- Three lines, never just the discounted total: the courier
             collects this amount and needs to see why it is lower. --}}
        <div class="space-y-1 rounded-b-2xl border-t border-stone-200 bg-stone-50 px-4 py-3">
            <div class="flex items-baseline justify-between text-sm">
                <span class="text-stone-600">Υποσύνολο</span>
                <span class="price font-semibold">{{ $money($order->subtotal) }}</span>
            </div>
            @if($order->hasDiscount())
                <div class="flex items-baseline justify-between text-sm text-emerald-700">
                    <span>Έκπτωση ({{ $order->coupon_code }})</span>
                    <span class="price font-semibold">−{{ $money($order->discount_amount) }}</span>
                </div>
            @endif
            <div class="flex items-baseline justify-between pt-1">
                <span class="text-base font-bold">Σύνολο</span>
                <span class="price font-display text-xl font-extrabold">{{ $money($order->total) }}</span>
            </div>
        </div>
    </section>

    <p class="text-center text-xs text-stone-400">Ανανεώνεται αυτόματα κάθε 15 δευτερόλεπτα</p>
</aside>

</div>
</div>
