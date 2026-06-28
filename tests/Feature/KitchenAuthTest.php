<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KitchenAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_kitchen(): void
    {
        $this->get('/kitchen')
            ->assertRedirect('/admin/login');
    }

    public function test_authenticated_user_can_access_kitchen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/kitchen')
            ->assertOk();
    }
}
