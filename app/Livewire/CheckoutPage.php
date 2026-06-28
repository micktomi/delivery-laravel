<?php

namespace App\Livewire;

use App\Actions\CreateOrder;
use App\Enums\PaymentMethod;
use App\Services\CartService;
use Livewire\Component;

class CheckoutPage extends Component
{
    public string $customer_name = '';
    public string $phone = '';
    public string $address = '';
    public string $floor_bell = '';
    public string $notes = '';
    public string $payment_method = PaymentMethod::Cash->value;

    public ?int $confirmedOrderNumber = null;

    public function mount(): void
    {
        if (app(CartService::class)->isEmpty()) {
            $this->redirect('/');
        }
    }

    public function submit(): void
    {
        $this->validate([
            'customer_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'address' => 'required|string|max:500',
            'floor_bell' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'payment_method' => 'required|in:' . implode(',', array_column(PaymentMethod::cases(), 'value')),
        ]);

        $order = app(CreateOrder::class)->execute([
            'customer_name' => $this->customer_name,
            'phone' => $this->phone,
            'address' => $this->address,
            'floor_bell' => $this->floor_bell ?: null,
            'notes' => $this->notes ?: null,
            'payment_method' => $this->payment_method,
        ]);

        $this->confirmedOrderNumber = $order->display_number;
    }

    public function render()
    {
        $cart = app(CartService::class)->items();
        $subtotal = app(CartService::class)->subtotal();

        return view('livewire.checkout-page', [
            'cart' => $cart,
            'subtotal' => $subtotal,
            'paymentMethods' => PaymentMethod::cases(),
        ])->layout('layouts.app');
    }
}
