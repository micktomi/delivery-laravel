<?php

namespace Tests\Feature;

use App\Filament\Pages\StoreSettings;
use App\Filament\Widgets\StoreOrdersStatus;
use App\Models\StoreSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $this->assertSame(StoreSetting::DEFAULT_STORE_NAME, $first->displayName());
        $this->assertSame(StoreSetting::DEFAULT_BRAND_PRIMARY, $first->brandPrimary());
        $this->assertSame(StoreSetting::DEFAULT_BRAND_ACCENT, $first->brandAccent());
        $this->assertNull($first->logoUrl());
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
            ->getComponents()[1]
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

    public function test_settings_page_persists_valid_store_identity_and_branding(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(StoreSettings::class)
            ->set('data.store_name', 'Το Κατάστημα')
            ->set('data.brand_primary', '#2468aC')
            ->set('data.brand_accent', '#123456')
            ->call('save')
            ->assertHasNoErrors();

        $settings = StoreSetting::current()->fresh();

        $this->assertSame('Το Κατάστημα', $settings->store_name);
        $this->assertSame('#2468AC', $settings->brand_primary);
        $this->assertSame('#123456', $settings->brand_accent);
        $this->assertSame('Το Κατάστημα', $settings->displayName());
    }

    public function test_settings_page_rejects_a_non_hex_brand_color(): void
    {
        $admin = User::factory()->admin()->create();

        Livewire::actingAs($admin)
            ->test(StoreSettings::class)
            ->set('data.brand_primary', 'orange')
            ->call('save')
            ->assertHasErrors(['data.brand_primary' => 'regex']);

        $this->assertSame(StoreSetting::DEFAULT_BRAND_PRIMARY, StoreSetting::current()->brandPrimary());
    }

    public function test_settings_page_stores_a_valid_logo_without_losing_it_on_an_unchanged_save(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $page = Livewire::actingAs($admin)->test(StoreSettings::class);

        $page->set('data.logo_path', [UploadedFile::fake()->image('logo.png', 320, 320)])
            ->call('save')
            ->assertHasNoErrors();

        $settings = StoreSetting::current()->fresh();
        $storedPath = $settings->logo_path;

        $this->assertStringStartsWith('branding/', $storedPath);
        $this->assertStringEndsWith('.png', $storedPath);
        Storage::disk('public')->assertExists($storedPath);
        $this->assertNotNull($settings->logoUrl());

        $this->get('/')
            ->assertOk()
            ->assertSee('src="'.$settings->logoUrl().'"', false)
            ->assertSee('alt="'.$settings->displayName().'"', false);

        $page->call('save')->assertHasNoErrors();

        $this->assertSame($storedPath, StoreSetting::current()->fresh()->logo_path);
        Storage::disk('public')->assertExists($storedPath);
    }

    public function test_public_layout_uses_stored_name_and_validated_brand_css_variables(): void
    {
        StoreSetting::current()->update([
            'store_name' => 'Το Κατάστημα',
            'brand_primary' => '#2468AC',
            'brand_accent' => '#123456',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Το Κατάστημα</title>', false)
            ->assertSee('content="#2468AC"', false)
            ->assertSee('--brand-primary: #2468AC;', false)
            ->assertSee('--brand-accent: #123456;', false)
            ->assertSee('>Το Κατάστημα</h1>', false);
    }

    public function test_public_layout_falls_back_when_branding_values_are_invalid_outside_the_ui(): void
    {
        StoreSetting::current()->update([
            'store_name' => str_repeat('x', StoreSetting::STORE_NAME_MAX_LENGTH + 1),
            'brand_primary' => 'red; background: url(https://example.test)',
            'brand_accent' => 'not-a-color',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Delivery Menu</title>', false)
            ->assertSee('--brand-primary: #D97706;', false)
            ->assertSee('--brand-accent: #1C1206;', false)
            ->assertDontSee('background: url', false);
    }
}
