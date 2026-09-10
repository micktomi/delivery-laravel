<?php

namespace Tests\Feature;

use App\Models\StoreSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuntimeBrandingPwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_manifest_returns_configured_store_identity(): void
    {
        StoreSetting::current()->update([
            'store_name' => 'Το Καφέ Παράδειγμα',
            'brand_primary' => '#2468AC',
        ]);

        $response = $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');

        $manifest = $response->json();

        $this->assertSame('Το Καφέ Παράδειγμα', $manifest['name']);
        $this->assertSame('Το Καφέ Παρ…', $manifest['short_name']);
        $this->assertSame('#2468AC', $manifest['theme_color']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame([
            ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ], $manifest['icons']);
    }

    public function test_runtime_manifest_falls_back_safely_for_invalid_branding(): void
    {
        StoreSetting::current()->update([
            'store_name' => str_repeat('x', StoreSetting::STORE_NAME_MAX_LENGTH + 1),
            'brand_primary' => 'red; background: url(https://example.test)"};alert(1)//',
        ]);

        $manifest = $this->get('/manifest.webmanifest')
            ->assertOk()
            ->json();

        $this->assertSame(StoreSetting::DEFAULT_STORE_NAME, $manifest['name']);
        $this->assertSame(StoreSetting::DEFAULT_BRAND_PRIMARY, $manifest['theme_color']);
        $this->assertStringNotContainsString('url(', (string) json_encode($manifest));
    }

    public function test_runtime_manifest_response_is_never_stored_by_the_browser(): void
    {
        $response = $this->get('/manifest.webmanifest')->assertOk();

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_public_layout_links_to_the_runtime_manifest_route(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<link rel="manifest" href="'.route('manifest').'">', false);
    }

    public function test_kitchen_board_uses_store_display_name_in_title_and_header(): void
    {
        StoreSetting::current()->update(['store_name' => 'Το Καφέ Παράδειγμα']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/kitchen')
            ->assertOk()
            ->assertSee('<title>Το Καφέ Παράδειγμα · Κουζίνα</title>', false)
            ->assertSee('>Το Καφέ Παράδειγμα<', false);
    }

    public function test_admin_quick_links_widget_uses_store_display_name(): void
    {
        StoreSetting::current()->update(['store_name' => 'Το Καφέ Παράδειγμα']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('>Το Καφέ Παράδειγμα<', false)
            ->assertDontSee('>Delivery Menu<', false);
    }

    public function test_admin_login_screen_uses_store_display_name_as_brand(): void
    {
        StoreSetting::current()->update(['store_name' => 'Το Καφέ Παράδειγμα']);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Το Καφέ Παράδειγμα');
    }
}
