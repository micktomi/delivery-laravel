<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\CartService;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;

class CreateOrder
{
    public function __construct(
        private CartService $cart,
        private PricingService $pricing,
    ) {}

    public function execute(array $checkoutData): Order
    {
        $cartItems = $this->cart->items();

        return DB::transaction(function () use ($cartItems, $checkoutData) {
            $subtotal = $this->pricing->subtotal($cartItems);
            $deliveryFee = 0.00;
            $total = $this->pricing->total($subtotal, $deliveryFee);

            $displayNumber = DB::table('orders')
                ->whereDate('created_at', today())
                ->lockForUpdate()
                ->count() + 1;

            $order = Order::create([
                'display_number' => $displayNumber,
                'status' => OrderStatus::Nea->value,
                'payment_method' => $checkoutData['payment_method'],
                'customer_name' => $checkoutData['customer_name'],
                'phone' => $checkoutData['phone'],
                'address' => $checkoutData['address'],
                'floor_bell' => $checkoutData['floor_bell'] ?? null,
                'notes' => $checkoutData['notes'] ?? null,
                'subtotal' => $subtotal,
                'delivery_fee' => $deliveryFee,
                'total' => $total,
                'placed_at' => now(),
            ]);

            foreach ($cartItems as $line) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $line['product_id'],
                    'product_name' => $line['product_name'],
                    'base_price' => $line['base_price'],
                    'quantity' => $line['quantity'],
                    'selected_options' => $line['selected_options'],
                    'line_total' => $line['line_total'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            $this->cart->clear();

            return $order;
        });
    }
}
