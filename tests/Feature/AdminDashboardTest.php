<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_replaces_filament_branding_with_delivery_menu_quick_links(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Κατάσταση Παραγγελιών')
            ->assertSee('Παύση παραγγελιών')
            ->assertSee('Delivery Menu')
            ->assertSee('Διαχείριση καταλόγου και παραγγελιών')
            ->assertSee('Προβολή δημόσιου μενού')
            ->assertSee('Kitchen board')
            ->assertSee('Driver')
            ->assertSee('Παραγγελίες')
            ->assertSee(route('menu'))
            ->assertSee(route('kitchen'))
            ->assertSee(route('driver.dashboard'))
            ->assertSee(route('filament.admin.resources.orders.index'))
            ->assertDontSee('Documentation')
            ->assertDontSee('GitHub')
            ->assertDontSee('filamentphp.com/docs')
            ->assertDontSee('github.com/filamentphp/filament')
            ->assertDontSee('fi-filament-info-widget')
            ->assertDontSee('aria-label="Filament"', false);

        $this->assertLessThan(
            strpos($response->getContent(), 'data-delivery-menu-dashboard-card'),
            strpos($response->getContent(), 'data-store-orders-status'),
        );
    }
}
