<?php

namespace App\Livewire;

use App\Actions\CreateOrder;
use App\Enums\PaymentMethod;
use App\Services\CartService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

class CheckoutPage extends Component
{
    private const LATEST_PUBLIC_ORDER_SESSION_KEY = 'latest_public_order_route_key';

    /** Orders allowed from one IP inside RATE_LIMIT_WINDOW seconds. */
    private const MAX_ORDERS_PER_WINDOW = 5;

    private const RATE_LIMIT_WINDOW = 900;

    public string $customer_name = '';

    public string $phone = '';

    public string $address = '';

    public string $floor_bell = '';

    public string $notes = '';

    public string $payment_method = PaymentMethod::Cash->value;

    public ?int $confirmedOrderNumber = null;

    public ?int $confirmedOrderId = null;

    public ?string $confirmedOrderToken = null;

    public function mount(): void
    {
        if (app(CartService::class)->isEmpty()) {
            $this->redirect('/');
        }
    }

    public function submit(): void
    {
        // A second tap, a retried request or a replayed snapshot must not
        // produce a second order.
        if ($this->confirmedOrderId !== null) {
            return;
        }

        $this->customer_name = trim($this->customer_name);
        $this->phone = $this->normalisePhone($this->phone);
        $this->address = trim($this->address);
        $this->floor_bell = trim($this->floor_bell);
        $this->notes = trim($this->notes);

        $this->validate([
            'customer_name' => 'required|string|min:2|max:255',
            'phone' => ['required', 'string', 'regex:/^(?:69\d{8}|2\d{9})$/'],
            'address' => 'required|string|min:5|max:500',
            'floor_bell' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'payment_method' => 'required|in:'.implode(',', array_column(PaymentMethod::cases(), 'value')),
        ], [
            'customer_name.min' => 'Συμπληρώστε το όνομά σας.',
            'phone.regex' => 'Συμπληρώστε έγκυρο ελληνικό τηλέφωνο (π.χ. 6912345678 ή 2101234567).',
            'address.min' => 'Συμπληρώστε πλήρη διεύθυνση (οδός και αριθμός).',
        ]);

        $limiterKey = 'checkout:'.request()->ip();

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_ORDERS_PER_WINDOW)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limiterKey) / 60);

            Log::warning('checkout.rate_limited', [
                'ip' => request()->ip(),
                'available_in_minutes' => $minutes,
            ]);

            $this->addError('checkout', 'Πολλές παραγγελίες σε σύντομο διάστημα. Δοκιμάστε ξανά σε '
                .max(1, $minutes).' λεπτά ή καλέστε μας.');

            return;
        }

        try {
            $order = app(CreateOrder::class)->execute([
                'customer_name' => $this->customer_name,
                'phone' => $this->phone,
                'address' => $this->address,
                'floor_bell' => $this->floor_bell ?: null,
                'notes' => $this->notes ?: null,
                'payment_method' => $this->payment_method,
            ]);
        } catch (ValidationException $e) {
            // Cart / catalogue problems are written for the customer to read.
            throw $e;
        } catch (Throwable $e) {
            // Never surface an exception to the customer, never log their details.
            Log::error('checkout.failed', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'cart_lines' => count(app(CartService::class)->items()),
            ]);

            $this->addError('checkout', 'Δεν ήταν δυνατή η καταχώριση της παραγγελίας. '
                .'Το καλάθι σας διατηρήθηκε — δοκιμάστε ξανά σε λίγο.');

            return;
        }

        RateLimiter::hit($limiterKey, self::RATE_LIMIT_WINDOW);

        session()->put(self::LATEST_PUBLIC_ORDER_SESSION_KEY, $order->getRouteKey());

        $this->confirmedOrderNumber = $order->display_number;
        $this->confirmedOrderId = $order->id;
        $this->confirmedOrderToken = $order->getRouteKey();
    }

    /**
     * Accept what customers actually type (+30, spaces, dashes) and store one
     * canonical form the kitchen can dial.
     */
    private function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/[^\d]/', '', $phone) ?? '';

        return preg_replace('/^(?:0030|30)(?=\d{10}$)/', '', $digits) ?? $digits;
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
