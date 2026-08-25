<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureKitchenAvailabilityAccess;
use App\Livewire\KitchenAvailability;
use App\Livewire\KitchenAvailabilityLogin;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class KitchenAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kitchen.availability_pin_hash' => Hash::make('654321')]);
        RateLimiter::clear('kitchen-availability-login');
        RateLimiter::clear('kitchen-availability-login:127.0.0.1');
    }

    public function test_guest_is_redirected_to_the_dedicated_pin_login(): void
    {
        $this->get(route('kitchen.availability'))
            ->assertRedirect(route('kitchen.availability.login'));
    }

    public function test_kitchen_navigation_links_to_availability_without_sharing_authorization(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('kitchen'))
            ->assertOk()
            ->assertSee('Διαθεσιμότητα')
            ->assertSee(route('kitchen.availability'));

        $this->get(route('kitchen.availability'))
            ->assertRedirect(route('kitchen.availability.login'));
    }

    public function test_hashed_pin_grants_only_the_kitchen_availability_session(): void
    {
        Livewire::test(KitchenAvailabilityLogin::class)
            ->set('pin', '654321')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('kitchen.availability'));

        $this->assertSame(
            EnsureKitchenAvailabilityAccess::fingerprint(config('kitchen.availability_pin_hash')),
            session(EnsureKitchenAvailabilityAccess::SESSION_KEY),
        );
        $this->assertGuest();
        $this->get(route('kitchen.availability'))->assertOk();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_login_is_locked_after_five_failed_attempts_from_one_device(): void
    {
        $component = Livewire::test(KitchenAvailabilityLogin::class);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->set('pin', '000000')
                ->call('login')
                ->assertHasErrors('pin');
        }

        $component->set('pin', '654321')
            ->call('login')
            ->assertHasErrors('pin')
            ->assertNoRedirect();

        $this->assertFalse(session(EnsureKitchenAvailabilityAccess::SESSION_KEY, false));
    }

    public function test_missing_or_malformed_configured_hash_fails_closed(): void
    {
        foreach ([null, '654321'] as $invalidHash) {
            config(['kitchen.availability_pin_hash' => $invalidHash]);

            Livewire::test(KitchenAvailabilityLogin::class)
                ->set('pin', '654321')
                ->call('login')
                ->assertHasErrors('pin')
                ->assertNoRedirect();

            $this->assertFalse(session(EnsureKitchenAvailabilityAccess::SESSION_KEY, false));
        }
    }

    public function test_changing_the_configured_pin_revokes_existing_access(): void
    {
        $this->withSession($this->authenticatedSession());
        config(['kitchen.availability_pin_hash' => Hash::make('111222')]);

        $this->get(route('kitchen.availability'))
            ->assertRedirect(route('kitchen.availability.login'));
    }

    public function test_page_shows_only_product_name_and_availability_data(): void
    {
        $product = $this->product('Freddo Espresso', true, [
            'description' => 'Μυστική περιγραφή προϊόντος',
            'base_price' => '47.35',
            'image' => 'products/secret-image.webp',
        ]);

        $this->withSession($this->authenticatedSession())
            ->get(route('kitchen.availability'))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('ON')
            ->assertSee('Αναζήτηση προϊόντος')
            ->assertSee('Πίσω στην Κουζίνα')
            ->assertSee(route('kitchen'))
            ->assertDontSee('Μυστική περιγραφή προϊόντος')
            ->assertDontSee('47.35')
            ->assertDontSee('secret-image.webp')
            ->assertDontSee($product->category->name);
    }

    public function test_staff_can_search_and_change_only_product_availability(): void
    {
        $espresso = $this->product('Freddo Espresso', true, ['base_price' => '3.20']);
        $toast = $this->product('Τοστ γαλοπούλα', false);

        $this->withSession($this->authenticatedSession());

        $component = Livewire::test(KitchenAvailability::class)
            ->assertSee($espresso->name)
            ->assertSee($toast->name)
            ->set('search', 'Espresso')
            ->assertSee($espresso->name)
            ->assertDontSee($toast->name)
            ->call('setAvailability', $espresso->id, false)
            ->assertHasNoErrors();

        $this->assertFalse($espresso->fresh()->is_available);
        $this->assertSame('3.20', $espresso->fresh()->base_price);

        $component->call('setAvailability', $espresso->id, true)
            ->assertHasNoErrors();

        $this->assertTrue($espresso->fresh()->is_available);
    }

    public function test_logout_revokes_availability_access(): void
    {
        $this->withSession($this->authenticatedSession());

        Livewire::test(KitchenAvailability::class)
            ->call('logout')
            ->assertRedirect(route('kitchen.availability.login'));

        $this->assertFalse(session(EnsureKitchenAvailabilityAccess::SESSION_KEY, false));
        $this->get(route('kitchen.availability'))
            ->assertRedirect(route('kitchen.availability.login'));
    }

    private function product(string $name, bool $available, array $attributes = []): Product
    {
        $category = Category::query()->create([
            'name' => 'Κατηγορία '.$name,
            'slug' => 'category-'.str()->slug($name),
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return Product::query()->create(array_merge([
            'category_id' => $category->id,
            'name' => $name,
            'description' => null,
            'base_price' => '2.80',
            'is_available' => $available,
            'sort_order' => 0,
        ], $attributes));
    }

    private function authenticatedSession(): array
    {
        return [
            EnsureKitchenAvailabilityAccess::SESSION_KEY => EnsureKitchenAvailabilityAccess::fingerprint(
                config('kitchen.availability_pin_hash'),
            ),
        ];
    }
}
