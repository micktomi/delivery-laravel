<?php

namespace Tests\Feature;

use App\Filament\Pages\StoreSettings;
use App\Filament\Widgets\StoreOrdersStatus;
use App\Models\StoreSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StoreSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_singleton_is_recreated_once_with_safe_defaults(): void
    {
        StoreSetting::query()->delete();

        $first = StoreSetting::current();
        $second = StoreSetting::current();

        $this->assertSame(StoreSetting::SINGLETON_ID, $first->getKey());
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertFalse($first->accepting_orders);
        $this->assertSame([], $first->opening_hours);
        $this->assertNull($first->closed_message);
        $this->assertDatabaseCount('store_settings', 1);
    }

    public function test_dashboard_widget_toggles_manual_order_acceptance_on_and_off(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-17 10:00', 'Europe/Athens'),
        );
        StoreSetting::current()->update([
            'accepting_orders' => false,
            'opening_hours' => [
                'monday' => [['open' => '09:00', 'close' => '11:00']],
            ],
        ]);
        $admin = User::factory()->admin()->create();

        try {
            $component = Livewire::actingAs($admin)
                ->test(StoreOrdersStatus::class)
                ->assertSee('ΔΕΝ ΔΕΧΟΜΑΣΤΕ ΠΑΡΑΓΓΕΛΙΕΣ')
                ->assertSee('Άνοιγμα παραγγελιών')
                ->call('toggleAcceptingOrders')
                ->assertSee('ΔΕΧΟΜΑΣΤΕ ΠΑΡΑΓΓΕΛΙΕΣ')
                ->assertSee('Παύση παραγγελιών');

            $this->assertTrue(StoreSetting::current()->accepting_orders);

            $component
                ->call('toggleAcceptingOrders')
                ->assertSee('ΔΕΝ ΔΕΧΟΜΑΣΤΕ ΠΑΡΑΓΓΕΛΙΕΣ')
                ->assertSee('Άνοιγμα παραγγελιών');

            $this->assertFalse(StoreSetting::current()->accepting_orders);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_settings_page_summarizes_days_and_copies_the_full_monday_schedule(): void
    {
        $admin = User::factory()->admin()->create();
        $mondayIntervals = [
            ['open' => '07:00', 'close' => '10:00'],
            ['open' => '18:00', 'close' => '01:30'],
        ];
        StoreSetting::current()->update([
            'opening_hours' => [
                'monday' => $mondayIntervals,
            ],
        ]);

        $this->actingAs($admin)
            ->get('/admin/store-settings')
            ->assertOk()
            ->assertSee('Ρυθμίσεις Καταστήματος')
            ->assertSee('Εβδομαδιαίο ωράριο')
            ->assertSee('Ανοιχτά')
            ->assertDontSee('Κλειστή ημέρα')
            ->assertSee('Αντιγραφή Δευτέρας σε όλες τις ημέρες')
            ->assertSee('Δευτέρα · 07:00–10:00, 18:00–01:30')
            ->assertSee('Τρίτη · Κλειστά')
            ->assertSee('Europe/Athens');

        $component = Livewire::actingAs($admin)
            ->test(StoreSettings::class);
        $weekdaySections = $component
            ->instance()
            ->form
            ->getComponents()[0]
            ->getChildComponentContainer()
            ->getComponents();

        $this->assertCount(7, $weekdaySections);

        foreach ($weekdaySections as $weekdaySection) {
            $this->assertTrue($weekdaySection->isCollapsed());
        }

        $component
            ->set('data.schedule.monday.open', true)
            ->set('data.schedule.monday.intervals', $mondayIntervals)
            ->set('data.schedule.tuesday.open', false)
            ->set('data.schedule.wednesday.open', false)
            ->call('copyMondayToAllDays');

        foreach (['tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $weekday) {
            $component->assertSet("data.schedule.{$weekday}.open", true);

            $copiedIntervals = array_map(
                fn (array $interval): array => [
                    'open' => CarbonImmutable::parse($interval['open'])->format('H:i'),
                    'close' => CarbonImmutable::parse($interval['close'])->format('H:i'),
                ],
                array_values($component->get("data.schedule.{$weekday}.intervals")),
            );

            $this->assertSame(
                $mondayIntervals,
                $copiedIntervals,
            );
        }
    }

    public function test_settings_page_saves_intervals_closed_day_and_closed_message(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(StoreSettings::class)
            ->set('data.schedule.monday.open', true)
            ->set('data.schedule.monday.intervals', [
                ['open' => '07:00', 'close' => '10:00'],
                ['open' => '19:00', 'close' => '01:30'],
            ])
            ->set('data.schedule.tuesday.open', false)
            ->set('data.closed_message', 'Κλειστά λόγω τεχνικού προβλήματος.')
            ->call('save')
            ->assertHasNoErrors();

        $settings = StoreSetting::current();

        $this->assertSame([
            ['open' => '07:00', 'close' => '10:00'],
            ['open' => '19:00', 'close' => '01:30'],
        ], $settings->opening_hours['monday']);
        $this->assertSame([], $settings->opening_hours['tuesday']);
        $this->assertSame(
            'Κλειστά λόγω τεχνικού προβλήματος.',
            $settings->closed_message,
        );
    }
}
