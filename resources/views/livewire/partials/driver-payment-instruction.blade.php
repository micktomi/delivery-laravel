@php
    /* Presentation only. The instruction sentence comes from PaymentMethod
       and is what the courier is audited against; the amount is repeated
       large above it because it is the one number read at the door. */
    $isViva = $order->payment_method === \App\Enums\PaymentMethod::Viva;
    $isVivaPaid = $isViva && $order->payment_status === 'paid';
    $formattedTotal = number_format($order->total, 2, ',', '.').' €';
@endphp

<div
    data-driver-payment-method="{{ $order->payment_method->value }}"
    data-driver-payment-status="{{ $order->payment_status ?? '' }}"
    @class([
        'rounded-xl border px-4 py-3',
        'border-emerald-300 bg-emerald-50 text-emerald-900' => $isVivaPaid,
        'border-red-300 bg-red-50 text-red-900' => $isViva && ! $isVivaPaid,
        'border-amber-300 bg-amber-50 text-amber-950' => ! $isViva,
    ])
>
    <div class="flex items-baseline justify-between gap-3">
        <span class="text-[11px] font-extrabold uppercase tracking-wide opacity-80">
            {{ $isVivaPaid ? 'Πληρωμένο online' : ($isViva ? 'Online πληρωμή' : 'Προς είσπραξη') }}
        </span>
        <span class="font-display text-2xl font-extrabold tabular-nums leading-none {{ $isVivaPaid ? 'line-through opacity-60' : '' }}">{{ $formattedTotal }}</span>
    </div>
    <div class="mt-1.5 text-sm font-extrabold leading-snug">
        {{ $order->payment_method->courierInstruction($order->payment_status, $formattedTotal) }}
    </div>
</div>
