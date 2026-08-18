<?php

namespace App\Livewire;

use App\Actions\CreateOrder;
use App\Enums\PaymentMethod;
use App\Services\CartService;
use App\Support\StoreSchedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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

    public string $customer_email = '';

    public string $address = '';

    public string $floor_bell = '';

    public string $notes = '';

    public string $payment_method = PaymentMethod::Cash->value;

    public string $checkoutToken = '';

    public ?int $confirmedOrderNumber = null;

    public ?int $confirmedOrderId = null;

    public ?string $confirmedOrderToken = null;

    /** Set when a coupon stopped qualifying at submit: the order still went through. */
    public ?string $droppedCouponCode = null;

    public function mount(): void
    {
        $this->checkoutToken = (string) Str::uuid();

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

        $storeSchedule = app(StoreSchedule::class);

        if (! $storeSchedule->isAcceptingOrders()) {
            $this->addError('checkout', $storeSchedule->closedCheckoutMessage());

            return;
        }

        $this->customer_name = trim($this->customer_name);
        $this->customer_email = trim($this->customer_email);
        $this->phone = $this->normalisePhone($this->phone);
        $this->address = trim($this->address);
        $this->floor_bell = trim($this->floor_bell);
        $this->notes = trim($this->notes);

        $this->validate([
            'customer_name' => 'required|string|min:2|max:255',
            'customer_email' => 'nullable|email:rfc|max:255',
            'phone' => ['required', 'string', 'regex:/^(?:69\d{8}|2\d{9})$/'],
            'address' => 'required|string|min:5|max:255',
            'floor_bell' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'payment_method' => 'required|in:'.implode(',', array_map(
                fn (PaymentMethod $method): string => $method->value,
                $this->availablePaymentMethods(),
            )),
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

        // Read before the order runs: a successful submit clears the cart.
        $attemptedCoupon = app(CartService::class)->couponCode();

        try {
            $order = app(CreateOrder::class)->execute([
                'customer_name' => $this->customer_name,
                'customer_email' => $this->customer_email ?: null,
                'phone' => $this->phone,
                'address' => $this->address,
                'floor_bell' => $this->floor_bell ?: null,
                'notes' => $this->notes ?: null,
                'payment_method' => $this->payment_method,
                'checkout_token' => $this->checkoutToken,
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

        if ($order->payment_method === PaymentMethod::Viva) {
            $this->redirectRoute('viva.start', ['order' => $order]);

            return;
        }

        // A coupon lost between the cart and this moment is worth a sentence on
        // the confirmation, not a rejected order.
        if ($attemptedCoupon !== null && $order->coupon_code === null) {
            $this->droppedCouponCode = $attemptedCoupon;
        }

        $this->confirmedOrderNumber = $order->display_number;
        $this->confirmedOrderId = $order->id;
        $this->confirmedOrderToken = $order->getRouteKey();
    }

    /**
     * The coupon is entered in the cart; here it can only be taken back off.
     * There is one coupon state and it lives in the cart session value.
     */
    public function removeCoupon(): void
    {
        app(CartService::class)->removeCoupon();
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
        $cart = app(CartService::class);
        $totals = $cart->totals();
        $appliedCoupon = $cart->couponCode();

        // Say why a coupon shown in the cart is no longer taking anything off,
        // rather than letting the discount line vanish on the way here.
        $couponNotice = $appliedCoupon && $totals['discount'] <= 0
            ? ($cart->coupon()?->rejectionReason($totals['subtotal'])
                ?? 'Ο κωδικός δεν είναι πλέον διαθέσιμος.')
            : null;
        $storeSchedule = app(StoreSchedule::class);
        $isAcceptingOrders = $storeSchedule->isAcceptingOrders();
        $closedStoreMessage = $storeSchedule->closedCheckoutMessage();
        $belowMinimumOrder = $totals['subtotal'] < (float) config('cart.minimum_order_amount', 5.00);

        return view('livewire.checkout-page', [
            'cart' => $cart->items(),
            'totals' => $totals,
            'appliedCoupon' => $appliedCoupon,
            'couponNotice' => $couponNotice,
            'paymentMethods' => $this->availablePaymentMethods(),
            'selectedPaymentMethod' => PaymentMethod::tryFrom($this->payment_method),
            'isAcceptingOrders' => $isAcceptingOrders,
            'closedStoreMessage' => $closedStoreMessage,
            'checkoutDisabled' => $belowMinimumOrder || ! $isAcceptingOrders,
        ])->layout('layouts.app');
    }

    /** @return list<PaymentMethod> */
    private function availablePaymentMethods(): array
    {
        $methods = [PaymentMethod::Cash, PaymentMethod::PosCourier];

        if ((bool) config('services.viva.enabled')) {
            $methods[] = PaymentMethod::Viva;
        }

        return $methods;
    }
}
