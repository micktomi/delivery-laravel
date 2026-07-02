<div wire:poll.15s class="min-h-screen bg-gray-50">

{{-- ══ HEADER ══ --}}
<header class="sticky top-0 z-10 bg-white border-b px-4 py-3 flex items-center gap-3">
    <a href="/" class="p-1 -ml-1 text-gray-500">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/>
        </svg>
    </a>
    <div>
        <h1 class="font-black text-lg leading-none">
            Παραγγελία #{{ str_pad($order->display_number, 3, '0', STR_PAD_LEFT) }}
        </h1>
        <p class="text-xs text-gray-400 mt-0.5">{{ $order->placed_at->format('d/m/Y · H:i') }}</p>
    </div>
</header>

<div class="px-6 py-8 max-w-sm mx-auto">

    {{-- ══ CUSTOMER ══ --}}
    <div class="bg-white rounded-2xl shadow-sm px-5 py-4 mb-8">
        <div class="font-bold text-base">{{ $order->customer_name }}</div>
        <div class="text-sm text-gray-500 mt-0.5">{{ $order->address }}</div>
        @if($order->floor_bell)
            <div class="text-sm text-gray-400">{{ $order->floor_bell }}</div>
        @endif
    </div>

    {{-- ══ PROGRESS STEPS ══ --}}
    @php
        $icons = ['📋', '☕', '✅', '🛵', '🏠'];
    @endphp

    <div class="relative">
        @foreach($steps as $i => $step)
            @php
                $done    = $i < $currentIndex;
                $current = $i === $currentIndex;
                $pending = $i > $currentIndex;
                $last    = $loop->last;
            @endphp

            <div class="flex gap-4">
                {{-- Dot + line column --}}
                <div class="flex flex-col items-center w-10 shrink-0">
                    {{-- Circle --}}
                    <div class="relative flex items-center justify-center w-10 h-10 rounded-full shrink-0
                        @if($done)    bg-amber-500
                        @elseif($current) bg-amber-500 ring-4 ring-amber-200
                        @else         bg-white border-2 border-gray-200
                        @endif"
                    >
                        @if($done)
                            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                            </svg>
                        @elseif($current)
                            <span class="text-white text-base">{{ $icons[$i] }}</span>
                            {{-- pulse ring --}}
                            <span class="absolute inset-0 rounded-full bg-amber-400 animate-ping opacity-30"></span>
                        @else
                            <span class="text-gray-300 text-base">{{ $icons[$i] }}</span>
                        @endif
                    </div>

                    {{-- Connector line --}}
                    @if(!$last)
                        <div class="w-0.5 flex-1 my-1 min-h-[2rem]
                            {{ $done ? 'bg-amber-400' : 'bg-gray-200' }}">
                        </div>
                    @endif
                </div>

                {{-- Label column --}}
                <div class="pb-8 {{ $last ? 'pb-0' : '' }} flex items-start pt-2">
                    <div>
                        <div class="font-{{ $current ? 'black' : ($done ? 'semibold' : 'medium') }}
                            text-{{ $current ? 'gray-900' : ($done ? 'gray-700' : 'gray-300') }}
                            text-base leading-none">
                            {{ $step->getLabel() }}
                        </div>
                        @if($current)
                            <div class="text-xs mt-1 font-semibold" style="color: var(--accent)">
                                Τρέχουσα κατάσταση
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ══ ORDER SUMMARY ══ --}}
    <div class="mt-8">
        <h2 class="font-bold text-gray-500 text-sm uppercase tracking-wider mb-3 px-1">Σύνοψη Παραγγελίας</h2>
        <div class="bg-white rounded-2xl shadow-sm divide-y divide-gray-100 overflow-hidden">
            @foreach($order->items as $item)
                <div class="px-5 py-4">
                    <div class="flex justify-between items-baseline font-semibold text-base">
                        <span>{{ $item->quantity }}× {{ $item->product_name }}</span>
                        <span style="color: var(--accent)">{{ number_format($item->line_total, 2) }}€</span>
                    </div>
                    @if(!empty($item->selected_options))
                        <div class="text-sm text-gray-400 mt-0.5 leading-snug">
                            {{ collect($item->selected_options)->pluck('value')->implode(' · ') }}
                        </div>
                    @endif
                    @if(!empty($item->notes))
                        <div class="text-sm mt-0.5" style="color: var(--accent-text)">📝 {{ $item->notes }}</div>
                    @endif
                </div>
            @endforeach
            <div class="px-5 py-4 flex justify-between items-baseline bg-gray-50">
                <span class="font-bold text-base text-gray-600">Σύνολο</span>
                <span class="font-black text-xl" style="color: var(--accent)">{{ number_format($order->total, 2) }}€</span>
            </div>
        </div>
    </div>

    {{-- ══ FOOTER NOTE ══ --}}
    <p class="text-center text-xs text-gray-300 mt-10">
        Αυτόματη ενημέρωση κάθε 15 δευτερόλεπτα
    </p>

</div>
</div>
