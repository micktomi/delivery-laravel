<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Widgets\VivaPaymentHealthWidget;
use App\Models\Order;
use App\Models\User;
use App\Support\VivaPaymentHealth;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class VivaPaymentHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_counts_only_non_cancelled_viva_payments_pending_for_more_than_thirty_minutes(): void
    {
        CarbonImmutable::setTestNow('2026-08-23 12:00:00');

        $this->vivaOrder(['created_at' => now()->subMinutes(31)]);
        $this->vivaOrder(['created_at' => now()->subMinutes(30)]);
        $this->vivaOrder(['created_at' => now()->subMinutes(29)]);
        $this->vivaOrder([
            'status' => OrderStatus::Cancelled->value,
            'created_at' => now()->subHour(),
        ]);
        $this->vivaOrder([
            'payment_status' => 'paid',
            'viva_transaction_id' => (string) Str::uuid(),
            'paid_at' => now()->subMinutes(40),
            'created_at' => now()->subHour(),
        ]);
        Order::factory()->create([
            'payment_method' => PaymentMethod::Cash->value,
            'created_at' => now()->subHour(),
        ]);

        $this->assertSame(1, app(VivaPaymentHealth::class)->counts()['pending']);
    }

    public function test_it_counts_only_deterministic_viva_payment_inconsistencies(): void
    {
        CarbonImmutable::setTestNow('2026-08-23 12:00:00');

        $this->vivaOrder([
            'payment_status' => 'paid',
            'viva_transaction_id' => null,
            'paid_at' => now(),
        ]);
        $this->vivaOrder([
            'payment_status' => 'paid',
            'viva_transaction_id' => (string) Str::uuid(),
            'paid_at' => null,
        ]);
        $this->vivaOrder([
            'payment_status' => 'paid',
            'viva_order_code' => null,
            'viva_transaction_id' => (string) Str::uuid(),
            'paid_at' => now(),
        ]);
        $this->vivaOrder(['viva_transaction_id' => (string) Str::uuid()]);
        $this->vivaOrder(['paid_at' => now()]);
        $this->vivaOrder([
            'viva_transaction_id' => (string) Str::uuid(),
            'paid_at' => now(),
        ]);

        $this->vivaOrder();
        $this->vivaOrder(['viva_order_code' => null]);
        $this->vivaOrder([
            'payment_status' => 'paid',
            'viva_transaction_id' => (string) Str::uuid(),
            'paid_at' => now(),
        ]);
        Order::factory()->create([
            'payment_method' => PaymentMethod::Cash->value,
            'payment_status' => 'paid',
            'viva_transaction_id' => (string) Str::uuid(),
            'paid_at' => now(),
        ]);

        $this->assertSame(6, app(VivaPaymentHealth::class)->counts()['inconsistent']);
    }

    public function test_the_widget_renders_healthy_zero_states_in_the_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(VivaPaymentHealthWidget::class)
            ->assertSee('Viva: εκκρεμείς πληρωμές')
            ->assertSee('Καμία εκκρεμότητα')
            ->assertSee('Viva: ασυνέπειες πληρωμών')
            ->assertSee('Καμία ασυνέπεια')
            ->assertSee('fi-color-success', false);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Viva: εκκρεμείς πληρωμές')
            ->assertSee('Viva: ασυνέπειες πληρωμών');
    }

    public function test_the_widget_renders_operational_counts_that_need_attention(): void
    {
        CarbonImmutable::setTestNow('2026-08-23 12:00:00');
        $admin = User::factory()->admin()->create();

        $this->vivaOrder(['created_at' => now()->subMinutes(31)]);
        $this->vivaOrder(['viva_transaction_id' => (string) Str::uuid()]);
        $this->vivaOrder(['paid_at' => now()]);

        Livewire::actingAs($admin)
            ->test(VivaPaymentHealthWidget::class)
            ->assertSeeInOrder(['Viva: εκκρεμείς πληρωμές', '1'])
            ->assertSee('Σε αναμονή πάνω από 30 λεπτά')
            ->assertSeeInOrder(['Viva: ασυνέπειες πληρωμών', '2'])
            ->assertSee('Χρειάζονται ανθρώπινο έλεγχο')
            ->assertSee('fi-color-danger', false);
    }

    private function vivaOrder(array $overrides = []): Order
    {
        static $orderCode = 7680701046572600;

        return Order::factory()->create(array_merge([
            'payment_method' => PaymentMethod::Viva->value,
            'payment_status' => 'pending',
            'viva_order_code' => (string) $orderCode++,
            'viva_transaction_id' => null,
            'paid_at' => null,
        ], $overrides));
    }
}
