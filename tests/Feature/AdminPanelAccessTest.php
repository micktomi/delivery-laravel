<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\StaffLogin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B1: without FilamentUser, Filament denied every user outside APP_ENV=local,
 * which locked the owner out of the panel in production. These tests run under
 * APP_ENV=testing, i.e. exactly the non-local path that used to 403.
 */
class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_the_panel_outside_the_local_environment(): void
    {
        $this->assertNotSame('local', config('app.env'));

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin')->assertSuccessful();
    }

    public function test_kitchen_staff_cannot_open_the_admin_panel(): void
    {
        $staff = User::factory()->create();

        $this->assertFalse($staff->refresh()->is_admin);
        $this->actingAs($staff)->get('/admin')->assertForbidden();
    }

    public function test_kitchen_staff_can_still_use_the_board(): void
    {
        $staff = User::factory()->create();

        $this->actingAs($staff)->get('/kitchen')->assertOk();
        $this->actingAs($staff)->get('/kitchen/history')->assertOk();
    }

    public function test_guests_are_redirected_to_the_login_screen(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    /**
     * The shop has one login form. Gating the panel on is_admin must not lock
     * kitchen staff out of the only door in the building.
     */
    public function test_kitchen_staff_can_log_in_and_land_on_the_board(): void
    {
        $staff = User::factory()->create(['email' => 'staff@example.gr']);

        Livewire::test(StaffLogin::class)
            ->fillForm(['email' => 'staff@example.gr', 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect(route('kitchen'));

        $this->assertAuthenticatedAs($staff);
        $this->get('/admin')->assertForbidden();
        $this->get('/kitchen')->assertOk();
    }

    public function test_admin_logging_in_lands_on_the_panel(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'owner@example.gr']);

        Livewire::test(StaffLogin::class)
            ->fillForm(['email' => 'owner@example.gr', 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect(filament()->getUrl());

        $this->assertAuthenticatedAs($admin);
    }

    public function test_wrong_credentials_are_still_refused(): void
    {
        User::factory()->create(['email' => 'staff@example.gr']);

        Livewire::test(StaffLogin::class)
            ->fillForm(['email' => 'staff@example.gr', 'password' => 'not-the-password'])
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
    }

    public function test_is_admin_cannot_be_mass_assigned(): void
    {
        $user = new User;
        $user->fill(['name' => 'X', 'email' => 'x@example.com', 'is_admin' => true]);

        $this->assertNotTrue($user->is_admin);
    }
}
