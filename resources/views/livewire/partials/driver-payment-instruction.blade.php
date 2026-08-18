@php
    $isViva = $order->payment_method === \App\Enums\PaymentMethod::Viva;
    $isVivaPaid = $isViva && $order->payment_status === 'paid';
    $formattedTotal = number_format($order->total, 2, ',', '.').' €';
@endphp

<div
    data-driver-payment-method="{{ $order->payment_method->value }}"
    data-driver-payment-status="{{ $order->payment_status ?? '' }}"
    @class([
        'rounded-xl border-2 px-4 py-3 text-center text-sm font-black',
        'border-emerald-300 bg-emerald-50 text-emerald-800' => $isVivaPaid,
        'border-red-300 bg-red-50 text-red-800' => $isViva && ! $isVivaPaid,
        'border-amber-300 bg-amber-50 text-amber-950' => ! $isViva,
    ])
>
    {{ $order->payment_method->courierInstruction($order->payment_status, $formattedTotal) }}
</div>
