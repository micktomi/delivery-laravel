<div class="min-h-screen bg-gray-50 py-6 px-4 sm:px-6 lg:px-8">
    <div class="max-w-4xl mx-auto">
        {{-- Header with back button --}}
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-2xl font-black text-gray-900 flex items-center gap-2">
                📜 Ιστορικό & Σύνολα Ημέρας
            </h1>
            <a href="/kitchen" class="bg-gray-800 hover:bg-gray-700 text-white font-bold px-4 py-2 rounded-xl transition flex items-center gap-1 text-sm shadow">
                ← Live Board
            </a>
        </div>

        {{-- Metrics Grid --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            {{-- Total Orders --}}
            <div class="bg-white rounded-2xl shadow-sm p-4 border border-gray-100 flex flex-col justify-between">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">Παραγγελίες Ημέρας</span>
                <span class="text-3xl font-black text-gray-800 mt-2">{{ $totalOrdersCount }}</span>
            </div>

            {{-- Completed/Sent --}}
            <div class="bg-white rounded-2xl shadow-sm p-4 border border-gray-100 flex flex-col justify-between">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">Ολοκληρώθηκαν / Έφυγαν</span>
                <span class="text-3xl font-black text-green-600 mt-2">{{ $completedOrSentCount }}</span>
            </div>

            {{-- Cancelled --}}
            <div class="bg-white rounded-2xl shadow-sm p-4 border border-gray-100 flex flex-col justify-between">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">Ακυρώθηκαν</span>
                <span class="text-3xl font-black text-red-500 mt-2">{{ $cancelledCount }}</span>
            </div>

            {{-- Daily Revenue --}}
            <div class="bg-white rounded-2xl shadow-sm p-4 border border-gray-100 flex flex-col justify-between" style="border-left: 4px solid var(--accent, #f59e0b)">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">Τζίρος Ημέρας</span>
                <span class="text-3xl font-black mt-2" style="color: var(--accent, #f59e0b)">{{ number_format($dailyRevenue, 2) }}€</span>
            </div>
        </div>

        {{-- Orders List --}}
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden border border-gray-100">
            <div class="px-5 py-4 border-b border-gray-100 bg-gray-50 flex items-center justify-between">
                <h2 class="font-bold text-gray-700">Λίστα Παραγγελιών</h2>
                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">{{ today()->format('d/m/Y') }}</span>
            </div>

            @if($orders->isEmpty())
                <div class="text-center text-gray-400 py-12">
                    Δεν υπάρχουν ολοκληρωμένες ή ακυρωμένες παραγγελίες για σήμερα.
                </div>
            @else
                <div class="divide-y divide-gray-100" x-data="{ activeOrder: null }">
                    @foreach($orders as $order)
                        <div class="p-5 hover:bg-gray-50 transition">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                                {{-- Left info --}}
                                <div class="flex items-center gap-4">
                                    <span class="font-black text-2xl text-gray-800">
                                        #{{ str_pad($order->display_number, 3, '0', STR_PAD_LEFT) }}
                                    </span>
                                    <div>
                                        <div class="font-bold text-base leading-snug">{{ $order->customer_name }}</div>
                                        <div class="text-sm text-gray-500 flex items-center gap-2 mt-0.5">
                                            <span>🕒 {{ $order->placed_at->format('H:i') }}</span>
                                            <span>·</span>
                                            <span>📞 {{ $order->phone }}</span>
                                        </div>
                                    </div>
                                </div>

                                {{-- Right info / status --}}
                                <div class="flex items-center justify-between md:justify-end gap-4 shrink-0">
                                    <span class="inline-block text-xs font-bold px-3 py-1 rounded-full
                                        @if($order->status === \App\Enums\OrderStatus::Completed) bg-gray-100 text-gray-800
                                        @elseif($order->status === \App\Enums\OrderStatus::Out) bg-purple-100 text-purple-800
                                        @elseif($order->status === \App\Enums\OrderStatus::Cancelled) bg-red-100 text-red-800
                                        @else bg-blue-100 text-blue-800
                                        @endif"
                                    >
                                        {{ $order->status->getLabel() }}
                                    </span>
                                    <span class="inline-block text-xs font-bold px-3 py-1 rounded-full
                                        {{ $order->payment_method->value === 'cash' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800' }}"
                                    >
                                        {{ $order->payment_method->getLabel() }}
                                    </span>
                                    <span class="w-24 text-right">
                                        <span class="block font-black text-lg text-gray-900">
                                            {{ number_format($order->total, 2) }}€
                                        </span>
                                        @if($order->hasDiscount())
                                            {{-- The row would otherwise read as a pricing mistake. --}}
                                            <span class="block text-[11px] font-bold leading-tight text-emerald-700">
                                                {{ number_format($order->subtotal, 2) }}€ − {{ number_format($order->discount_amount, 2) }}€
                                                <span class="block text-gray-400">{{ $order->coupon_code }}</span>
                                            </span>
                                        @endif
                                    </span>
                                    {{-- Expand Button --}}
                                    <button 
                                        x-on:click="activeOrder = (activeOrder === {{ $order->id }} ? null : {{ $order->id }})"
                                        class="p-2 text-gray-400 hover:text-gray-600 transition"
                                    >
                                        <svg class="w-5 h-5 transform transition-transform" :class="activeOrder === {{ $order->id }} ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            {{-- Expandable Details --}}
                            <div 
                                x-show="activeOrder === {{ $order->id }}" 
                                class="mt-4 pt-4 border-t border-gray-100 space-y-4"
                                x-cloak
                            >
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    {{-- Order Items --}}
                                    <div class="bg-gray-50 rounded-xl p-4">
                                        <h4 class="font-bold text-xs text-gray-400 uppercase tracking-wider mb-2">Προϊόντα</h4>
                                        <div class="space-y-2">
                                            @foreach($order->items as $item)
                                                <div class="text-sm">
                                                    <div class="font-semibold text-gray-800">
                                                        {{ $item->quantity }}× {{ $item->product_name }}
                                                    </div>
                                                    @if(!empty($item->selected_options))
                                                        <div class="text-xs text-gray-500 pl-3 leading-snug mt-0.5">
                                                            · {{ collect($item->selected_options)->pluck('value')->implode(' · ') }}
                                                        </div>
                                                    @endif
                                                    @if($item->notes)
                                                        <div class="text-xs text-amber-700 pl-3 mt-0.5">📝 {{ $item->notes }}</div>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>

                                    {{-- Customer & Delivery details --}}
                                    <div class="bg-gray-50 rounded-xl p-4 space-y-3 text-sm">
                                        <div>
                                            <h4 class="font-bold text-xs text-gray-400 uppercase tracking-wider">Διεύθυνση</h4>
                                            <div class="font-semibold text-gray-800 mt-0.5">{{ $order->address }}</div>
                                            @if($order->floor_bell)
                                                <div class="text-xs text-gray-500">{{ $order->floor_bell }}</div>
                                            @endif
                                        </div>
                                        @if($order->notes)
                                            <div>
                                                <h4 class="font-bold text-xs text-gray-400 uppercase tracking-wider">Σημειώσεις Παραγγελίας</h4>
                                                <div class="text-gray-700 bg-amber-50 rounded-lg p-2 mt-1">
                                                    📝 {{ $order->notes }}
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
