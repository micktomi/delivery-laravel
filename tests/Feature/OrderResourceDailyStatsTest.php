<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Widgets\DailyOrderStatsWidget;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Coverage for the /kitchen/history replacement: the same "today's orders +
 * totals" view, now inside the Filament OrderResource, gated by is_admin
 * instead of bare auth.
 *
 * DailyOrderStatsWidget deliberately owns its own `date` state instead of
 * reading the OrderResource table's filter (that coupling, via
 * InteractsWithPageTable, desynced from the table and crashed Livewire on
 * hydration — see git history). These tests exercise the widget directly.
 */
class OrderResourceDailyStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_kitchen_history_route_no_longer_exists(): void
    {
        $this->get('/kitchen/history')->assertNotFound();
    }

    public function test_a_non_admin_cannot_open_the_orders_resource(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->get('/admin/orders')->assertForbidden();
    }

    public function test_a_date_with_no_orders_reports_all_zero_metrics(): void
    {
        Order::factory()->status(OrderStatus::Completed)->create(['total' => 50.00, 'created_at' => now()->subDays(3)]);

        $stats = Livewire::test(DailyOrderStatsWidget::class)
            ->set('date', now()->subDays(10)->toDateString())
            ->instance()
            ->getStats();

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['completed_or_out']);
        $this->assertSame(0, $stats['cancelled']);
        $this->assertSame(0.0, $stats['revenue']);
    }

    public function test_a_date_with_orders_reports_metrics_for_only_that_date(): void
    {
        $target = now()->subDays(2);

        Order::factory()->status(OrderStatus::Completed)->create(['total' => 10.50, 'created_at' => $target]);
        Order::factory()->status(OrderStatus::Out)->create(['total' => 15.00, 'created_at' => $target]);
        Order::factory()->status(OrderStatus::Cancelled)->create(['total' => 20.00, 'created_at' => $target]);
        Order::factory()->status(OrderStatus::Nea)->create(['total' => 5.00, 'created_at' => $target]);

        $stats = Livewire::test(DailyOrderStatsWidget::class)
            ->set('date', $target->toDateString())
            ->instance()
            ->getStats();

        $this->assertSame(4, $stats['total']);
        $this->assertSame(2, $stats['completed_or_out']);
        $this->assertSame(1, $stats['cancelled']);
        $this->assertSame(30.50, $stats['revenue']);
    }

    public function test_orders_from_another_date_are_excluded(): void
    {
        Order::factory()->status(OrderStatus::Completed)->create(['total' => 100.00, 'created_at' => now()->subDay()]);

        $stats = Livewire::test(DailyOrderStatsWidget::class)
            ->set('date', today()->toDateString())
            ->instance()
            ->getStats();

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0.0, $stats['revenue']);
    }

    public function test_cancelled_orders_are_excluded_from_revenue(): void
    {
        $target = today();

        Order::factory()->status(OrderStatus::Completed)->create(['total' => 10.00, 'created_at' => $target]);
        Order::factory()->status(OrderStatus::Cancelled)->create(['total' => 500.00, 'created_at' => $target]);

        $stats = Livewire::test(DailyOrderStatsWidget::class)
            ->set('date', $target->toDateString())
            ->instance()
            ->getStats();

        $this->assertSame(10.00, $stats['revenue']);
        $this->assertSame(1, $stats['cancelled']);
    }

    public function test_defaults_to_today_and_recovers_from_an_empty_date_update(): void
    {
        Order::factory()->status(OrderStatus::Completed)->create(['total' => 7.00, 'created_at' => today()]);

        $widget = Livewire::test(DailyOrderStatsWidget::class);

        $this->assertSame(today()->toDateString(), $widget->get('date'));

        // A cleared native date input reports an empty string, never null;
        // this must not throw and must fall back to a usable date.
        $widget->set('date', '')->assertOk();

        $this->assertSame(today()->toDateString(), $widget->get('date'));
        $this->assertSame(1, $widget->instance()->getStats()['total']);
    }

    public function test_the_orders_page_and_its_table_filter_render_without_error(): void
    {
        Order::factory()->status(OrderStatus::Completed)->create(['total' => 12.00, 'created_at' => today()]);
        Order::factory()->status(OrderStatus::Completed)->create(['total' => 8.00, 'created_at' => now()->subDay()]);

        $page = Livewire::test(ListOrders::class)->assertOk();

        $page->filterTable('created_at', ['date' => now()->subDay()->toDateString()])
            ->assertOk()
            ->assertCanSeeTableRecords(Order::whereDate('created_at', now()->subDay())->get())
            ->assertCanNotSeeTableRecords(Order::whereDate('created_at', today())->get());

        $page->filterTable('created_at', ['date' => null])
            ->assertOk()
            ->assertCanSeeTableRecords(Order::all());
    }
}
