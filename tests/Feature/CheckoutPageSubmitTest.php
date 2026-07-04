<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Livewire\CheckoutPage;
use App\Models\Order;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutPageSubmitTest extends TestCase
{
    use RefreshDatabase;

    private function seedCart(): void
    {
        app(CartService::class)->add([
            'product_id' => 1,
            'product_name' => 'Freddo Espresso',
            'base_price' => 2.80,
            'selected_options' => [],
            'quantity' => 1,
            'line_total' => 2.80,
            'notes' => '',
        ]);
    }

    public function test_submit_validates_required_customer_fields(): void
    {
        $this->seedCart();

        Livewire::test(CheckoutPage::class)
            ->set('customer_name', '')
            ->set('phone', '')
            ->set('address', '')
            ->call('submit')
            ->assertHasErrors(['customer_name' => 'required'])
            ->assertHasErrors(['phone' => 'required'])
            ->assertHasErrors(['address' => 'required']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_submit_rejects_invalid_payment_method(): void
    {
        $this->seedCart();

        Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', 'bitcoin')
            ->call('submit')
            ->assertHasErrors(['payment_method' => 'in']);

        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * NOTE: CheckoutPage::submit() does not perform a Livewire redirect to the
     * tracking page. It only sets $confirmedOrderNumber / $confirmedOrderId,
     * which switches the Blade view into an in-page confirmation screen
     * containing a manual link to the tracking route. This test documents
     * that actual behavior rather than a redirect.
     */
    public function test_submit_shows_confirmation_state_with_tracking_link_after_success(): void
    {
        $this->seedCart();

        $component = Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Cash->value)
            ->call('submit')
            ->assertHasNoErrors();

        $order = Order::firstOrFail();

        $component
            ->assertNoRedirect()
            ->assertSet('confirmedOrderId', $order->id)
            ->assertSet('confirmedOrderNumber', $order->display_number)
            ->assertSee(route('order.track', $order));
    }
}
