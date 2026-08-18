<?php

namespace Tests\Feature;

use App\Actions\TransitionDriverDelivery;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Livewire\DriverDashboard;
use App\Livewire\DriverLogin;
use App\Models\Driver;
use App\Models\DriverShift;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DriverWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_login_uses_a_hashed_pin_and_starts_a_shift(): void
    {
        $driver = Driver::factory()->create(['pin' => Hash::make('654321')]);

        Livewire::test(DriverLogin::class)
            ->set('pin', '654321')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('driver.dashboard'));

        $this->assertAuthenticatedAs($driver, 'driver');
        $this->assertNotSame('654321', $driver->fresh()->pin);
        $this->assertTrue(Hash::check('654321', $driver->fresh()->pin));
        $this->assertDatabaseHas('driver_shifts', ['driver_id' => $driver->id, 'ended_at' => null]);
    }

    public function test_driver_routes_redirect_guests_to_driver_login(): void
    {
        $this->get(route('driver.dashboard'))
            ->assertRedirect(route('driver.login'));
    }

    public function test_active_delivery_has_call_and_address_only_navigation_actions(): void
    {
        $driver = Driver::factory()->create();
        $address = 'Λεωφόρος Κηφισίας 10, Αθήνα';
        $notes = 'Χτύπησε το πλαϊνό κουδούνι & περίμενε';
        $order = Order::factory()->status(OrderStatus::Ready)->create([
            'phone' => '+30 210 123 4567',
            'address' => $address,
            'floor_bell' => '3ος όροφος',
            'notes' => $notes,
            'total' => '42.75',
        ]);
        app(TransitionDriverDelivery::class)->claim($order->id, $driver);

        $html = Livewire::actingAs($driver, 'driver')
            ->test(DriverDashboard::class)
            ->html();

        $mapsUrl = 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($address);

        $this->assertStringContainsString('href="tel:+302101234567"', $html);
        $this->assertStringContainsString('📞 Κλήση', $html);
        $this->assertStringContainsString('📍 Πλοήγηση', $html);
        $this->assertStringContainsString('href="'.e($mapsUrl).'"', $html);
        $this->assertStringNotContainsString(rawurlencode($notes), $mapsUrl);
        $this->assertStringContainsString(e($notes), $html);
        $this->assertStringContainsString('42,75 €', $html);
        $this->assertStringContainsString('Παρέλαβα την παραγγελία', $html);
    }

    public static function courierPaymentInstructions(): array
    {
        return [
            'cash collection' => [
                PaymentMethod::Cash,
                null,
                'ΕΙΣΠΡΑΞΗ ΜΕΤΡΗΤΩΝ: 12,30 €',
            ],
            'courier POS collection' => [
                PaymentMethod::PosCourier,
                null,
                'ΠΛΗΡΩΜΗ ΜΕ POS: 12,30 €',
            ],
            'paid Viva order' => [
                PaymentMethod::Viva,
                'paid',
                'ΠΛΗΡΩΜΕΝΟ — ΜΗΝ ΕΙΣΠΡΑΞΕΙΣ',
            ],
        ];
    }

    #[DataProvider('courierPaymentInstructions')]
    public function test_active_delivery_shows_operational_payment_instruction(
        PaymentMethod $method,
        ?string $paymentStatus,
        string $instruction,
    ): void {
        $driver = Driver::factory()->create();
        $order = Order::factory()->status(OrderStatus::Ready)->create([
            'payment_method' => $method->value,
            'payment_status' => $paymentStatus,
            'total' => '12.30',
        ]);
        app(TransitionDriverDelivery::class)->claim($order->id, $driver);

        Livewire::actingAs($driver, 'driver')
            ->test(DriverDashboard::class)
            ->assertSee($instruction);
    }

    public function test_dashboard_claim_uses_the_authenticated_driver_not_frontend_input(): void
    {
        $driver = Driver::factory()->create();
        $order = Order::factory()->status(OrderStatus::Ready)->create();

        Livewire::actingAs($driver, 'driver')
            ->test(DriverDashboard::class)
            ->call('claim', $order->id)
            ->assertHasNoErrors();

        $this->assertSame($driver->id, $order->fresh()->driver_id);
        $this->assertSame(DeliveryStatus::Assigned, $order->fresh()->delivery_status);
    }

    public function test_claim_is_atomic_and_only_one_driver_can_receive_an_order(): void
    {
        $firstDriver = Driver::factory()->create();
        $secondDriver = Driver::factory()->create();
        $order = Order::factory()->status(OrderStatus::Ready)->create();

        app(TransitionDriverDelivery::class)->claim($order->id, $firstDriver);

        try {
            app(TransitionDriverDelivery::class)->claim($order->id, $secondDriver);
            $this->fail('Expected the second claim to be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('δεν είναι πλέον διαθέσιμη', $e->validator->errors()->first('delivery'));
        }

        $this->assertSame($firstDriver->id, $order->fresh()->driver_id);
        $this->assertDatabaseCount('order_driver_transitions', 1);
    }

    public function test_driver_lifecycle_updates_order_progress_and_writes_an_audit_row_for_every_step(): void
    {
        $driver = Driver::factory()->create();
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $transition = app(TransitionDriverDelivery::class);

        $transition->claim($order->id, $driver);
        $transition->pickUp($order->id, $driver);
        $transition->outForDelivery($order->id, $driver);
        $transition->deliver($order->id, $driver);

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Completed, $fresh->status);
        $this->assertSame(DeliveryStatus::Delivered, $fresh->delivery_status);
        $this->assertDatabaseCount('order_driver_transitions', 4);
        $this->assertDatabaseHas('order_driver_transitions', [
            'order_id' => $order->id,
            'driver_id' => $driver->id,
            'from_status' => DeliveryStatus::OutForDelivery->value,
            'to_status' => DeliveryStatus::Delivered->value,
        ]);
    }

    public function test_a_different_driver_cannot_advance_an_assigned_delivery(): void
    {
        $assignee = Driver::factory()->create();
        $otherDriver = Driver::factory()->create();
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        $transition = app(TransitionDriverDelivery::class);
        $transition->claim($order->id, $assignee);

        $this->expectException(ValidationException::class);
        $transition->pickUp($order->id, $otherDriver);
    }

    public function test_driver_cannot_end_a_shift_with_an_active_delivery(): void
    {
        $driver = Driver::factory()->create();
        $order = Order::factory()->status(OrderStatus::Ready)->create();
        app(TransitionDriverDelivery::class)->claim($order->id, $driver);

        Livewire::actingAs($driver, 'driver')
            ->test(DriverDashboard::class)
            ->call('endShift')
            ->assertHasErrors('driver');

        $this->assertAuthenticatedAs($driver, 'driver');
    }

    public function test_ending_a_shift_closes_its_record_and_logs_the_driver_out(): void
    {
        $driver = Driver::factory()->create();
        $shift = DriverShift::query()->create(['driver_id' => $driver->id, 'started_at' => now()]);
        session(['driver_shift_id' => $shift->id]);

        Livewire::actingAs($driver, 'driver')
            ->test(DriverDashboard::class)
            ->call('endShift')
            ->assertRedirect(route('driver.login'));

        $this->assertGuest('driver');
        $this->assertNotNull($shift->fresh()->ended_at);
    }
}
