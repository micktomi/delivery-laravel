<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLogViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_log_viewer_is_not_exposed_in_the_admin_panel(): void
    {
        $this->get('/admin/log-viewer')->assertNotFound();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/admin/log-viewer')
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('/admin/log-viewer')
            ->assertDontSee('Application logs')
            ->assertDontSee('Laravel / Application')
            ->assertDontSee('Payments / Viva');
    }
}
