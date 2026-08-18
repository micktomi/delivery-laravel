<?php

namespace Tests\Feature;

use App\Livewire\MenuPage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StorefrontAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_closed_storefront_shows_message_and_next_opening(): void
    {
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse('2026-08-17 20:00', 'Europe/Athens'),
        );

        config()->set('store.accepting_orders', true);
        config()->set('store.opening_hours', [
            'tuesday' => [['07:00', '14:00']],
        ]);
        config()->set('store.closed_message', 'Οι παραγγελίες θα ανοίξουν ξανά το πρωί.');

        try {
            Livewire::test(MenuPage::class)
                ->assertSee('Κλειστά — ανοίγουμε αύριο στις 07:00')
                ->assertSee('Οι παραγγελίες θα ανοίξουν ξανά το πρωί.');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
