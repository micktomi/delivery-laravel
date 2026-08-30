<?php

namespace Tests\Feature;

use App\Actions\TransitionDriverDelivery;
use App\Enums\OrderStatus;
use App\Livewire\DriverDashboard;
use App\Models\Driver;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class OrderPersonalDataRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-28 12:00:00');
        config()->set('retention.orders.anonymization_days', 30);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_terminal_order_pii_is_anonymized_without_removing_financial_or_payment_history(): void
    {
        $order = Order::factory()->status(OrderStatus::Completed)->create([
            'customer_name' => 'Μαρία Παπαδοπούλου',
            'customer_email' => 'maria@example.gr',
            'phone' => '6912345678',
            'address' => 'Δημοκρατίας 42',
            'floor_bell' => '2ος, Παπαδόπουλος',
            'notes' => 'Καλέστε όταν φτάσετε',
            'subtotal' => '7.50',
            'delivery_fee' => '1.00',
            'total' => '8.50',
            'viva_order_code' => '7680701046572600',
            'viva_transaction_id' => '11111111-2222-4333-8444-555555555555',
            'paid_at' => now()->subDays(31),
            'placed_at' => now()->subDays(31),
            'created_at' => now()->subDays(31),
        ]);
        $order->items()->create([
            'product_name' => 'Freddo Espresso',
            'base_price' => '7.50',
            'quantity' => 1,
            'selected_options' => [],
            'line_total' => '7.50',
            'notes' => 'Χωρίς ζάχαρη — για τη Μαρία',
        ]);

        $this->artisan('orders:anonymize-personal-data')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('Ανωνυμοποιημένο', $order->customer_name);
        $this->assertNull($order->customer_email);
        $this->assertSame('Ανωνυμοποιημένο', $order->phone);
        $this->assertSame('Ανωνυμοποιημένη διεύθυνση', $order->address);
        $this->assertNull($order->floor_bell);
        $this->assertNull($order->notes);
        $this->assertNotNull($order->personal_data_anonymized_at);
        $this->assertEquals(8.50, $order->total);
        $this->assertSame('7680701046572600', $order->viva_order_code);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $order->viva_transaction_id);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->items()->firstOrFail()->notes);
    }

    public function test_retention_skips_open_recent_and_already_anonymized_orders_idempotently(): void
    {
        $oldOpen = Order::factory()->status(OrderStatus::Preparing)->create([
            'customer_name' => 'Ανοιχτή Παραγγελία',
            'placed_at' => now()->subDays(31),
            'created_at' => now()->subDays(31),
        ]);
        $recentCompleted = Order::factory()->status(OrderStatus::Completed)->create([
            'customer_name' => 'Πρόσφατη Παραγγελία',
            'placed_at' => now()->subDays(29),
            'created_at' => now()->subDays(29),
        ]);
        $alreadyAnonymized = Order::factory()->status(OrderStatus::Completed)->create([
            'customer_name' => 'Ανωνυμοποιημένο',
            'personal_data_anonymized_at' => now()->subDay(),
            'placed_at' => now()->subDays(31),
            'created_at' => now()->subDays(31),
        ]);

        $this->artisan('orders:anonymize-personal-data')->assertExitCode(0);
        $this->artisan('orders:anonymize-personal-data')->assertExitCode(0);

        $this->assertSame('Ανοιχτή Παραγγελία', $oldOpen->fresh()->customer_name);
        $this->assertSame('Πρόσφατη Παραγγελία', $recentCompleted->fresh()->customer_name);
        $this->assertSame(
            now()->subDay()->toDateTimeString(),
            $alreadyAnonymized->fresh()->personal_data_anonymized_at->toDateTimeString(),
        );
    }

    public function test_only_the_assigned_driver_can_view_delivery_pii(): void
    {
        $driver = Driver::factory()->create();
        $order = Order::factory()->status(OrderStatus::Ready)->create([
            'customer_name' => 'Ελένη Ιδιωτική',
            'phone' => '6912345678',
            'address' => 'Απόρρητη διεύθυνση 10',
            'floor_bell' => '4ος',
            'notes' => 'Προσωπική οδηγία',
        ]);

        $availableHtml = Livewire::actingAs($driver, 'driver')
            ->test(DriverDashboard::class)
            ->html();

        $this->assertStringNotContainsString('Ελένη Ιδιωτική', $availableHtml);
        $this->assertStringNotContainsString('6912345678', $availableHtml);
        $this->assertStringNotContainsString('Απόρρητη διεύθυνση 10', $availableHtml);

        app(TransitionDriverDelivery::class)->claim($order->id, $driver);

        $assignedHtml = Livewire::actingAs($driver, 'driver')
            ->test(DriverDashboard::class)
            ->html();

        $this->assertStringContainsString('Ελένη Ιδιωτική', $assignedHtml);
        $this->assertStringContainsString('6912345678', $assignedHtml);
        $this->assertStringContainsString('Απόρρητη διεύθυνση 10', $assignedHtml);
    }
}
