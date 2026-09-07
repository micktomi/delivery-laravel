<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracking_page_is_publicly_accessible(): void
    {
        $order = Order::factory()->create();

        $this->get(route('order.track', $order))
            ->assertOk();
    }

    public function test_tracking_page_returns_404_for_missing_order(): void
    {
        $this->get('/order/999999/track')
            ->assertNotFound();
    }

    public function test_tracking_page_shows_order_number(): void
    {
        $order = Order::factory()->create(['display_number' => 7]);

        $this->get(route('order.track', $order))
            ->assertSee('007');
    }

    public function test_tracking_page_shows_current_status_label(): void
    {
        $order = Order::factory()->status(OrderStatus::Preparing)->create();

        $this->get(route('order.track', $order))
            // The tracking page speaks to the customer, not the kitchen: the enum
            // label ΕΤΟΙΜΑΖΕΤΑΙ is shown as its customer-facing wording.
            ->assertSee('Ετοιμάζεται');
    }

    public function test_tracking_page_requires_no_authentication(): void
    {
        $order = Order::factory()->create();

        $this->get(route('order.track', $order))
            ->assertOk()
            ->assertDontSee('login');
    }

    public static function persistedPaymentLabels(): array
    {
        return [
            'cash' => [PaymentMethod::Cash, null, 'Μετρητά κατά την παράδοση'],
            'courier POS' => [PaymentMethod::PosCourier, null, 'POS στον courier'],
            'Viva paid' => [PaymentMethod::Viva, 'paid', 'Viva Wallet — Πληρωμένο'],
            'Viva pending' => [PaymentMethod::Viva, 'pending', 'Viva Wallet — Αναμονή επιβεβαίωσης'],
        ];
    }

    #[DataProvider('persistedPaymentLabels')]
    public function test_tracking_shows_persisted_payment_method_and_status(
        PaymentMethod $method,
        ?string $paymentStatus,
        string $label,
    ): void {
        $order = Order::factory()->create([
            'payment_method' => $method->value,
            'payment_status' => $paymentStatus,
        ]);

        $this->get(route('order.track', $order))
            ->assertOk()
            ->assertSee($label);
    }
}
