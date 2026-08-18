<?php

namespace Tests\Feature;

use App\Filament\Pages\LogViewer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

class AdminLogViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_admin_can_open_the_log_viewer(): void
    {
        $this->get('/admin/log-viewer')->assertRedirect('/admin/login');

        $staff = User::factory()->create();
        $this->actingAs($staff)->get('/admin/log-viewer')->assertForbidden();

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->get('/admin/log-viewer')
            ->assertOk()
            ->assertSee('Laravel / Application')
            ->assertSee('Payments / Viva')
            ->assertSee('Ανανέωση')
            ->assertDontSee('Download')
            ->assertDontSee('Delete');
    }

    public function test_viewer_uses_a_fixed_whitelist_bounded_tail_search_and_escaped_output(): void
    {
        $path = storage_path('logs/payments-9999-99-99.log');

        if (File::exists($path)) {
            $this->fail('The isolated log-viewer test file already exists.');
        }

        $lines = ['OLD-SENTINEL'];

        for ($line = 1; $line <= 510; $line++) {
            $lines[] = 'routine line '.$line;
        }

        $lines[] = 'needle <script>alert("xss")</script>';

        File::put($path, implode(PHP_EOL, $lines).PHP_EOL);
        touch($path, time() + 3600);

        try {
            $admin = User::factory()->admin()->create();

            Livewire::actingAs($admin)
                ->test(LogViewer::class)
                ->call('selectSource', 'payments')
                ->assertSet('source', 'payments')
                ->assertSee('payments-9999-99-99.log')
                ->assertDontSee('OLD-SENTINEL')
                ->assertSee('needle')
                ->assertSee('&lt;script&gt;', false)
                ->assertDontSee('<script>alert("xss")</script>', false)
                ->set('search', 'needle')
                ->assertSee('needle')
                ->assertDontSee('routine line 510')
                ->call('selectSource', '../../.env')
                ->assertSet('source', 'payments')
                ->assertDontSee('APP_KEY=');
        } finally {
            File::delete($path);
        }
    }
}
