<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B2: tracking pages used to be addressed by an auto-increment id, so walking
 * /order/1/track, /order/2/track ... exposed every customer's name, address,
 * phone-adjacent details and order contents.
 */
class OrderTrackingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequential_ids_no_longer_resolve_to_an_order(): void
    {
        $order = Order::factory()->create(['customer_name' => 'Μαρία Παπαδοπούλου']);

        $this->get('/order/'.$order->id.'/track')->assertNotFound();
        $this->get('/order/1/track')->assertNotFound();
        $this->get('/order/2/track')->assertNotFound();
    }

    public function test_the_token_link_still_works_for_the_customer(): void
    {
        $order = Order::factory()->create(['customer_name' => 'Μαρία Παπαδοπούλου']);

        $this->get(route('order.track', $order))
            ->assertOk()
            ->assertSee('Μαρία Παπαδοπούλου');
    }

    public function test_a_wrong_token_does_not_leak_another_order(): void
    {
        Order::factory()->create(['customer_name' => 'Μαρία Παπαδοπούλου']);
        $other = Order::factory()->create(['customer_name' => 'Γιώργος Νικολάου']);

        $this->get('/order/'.str_repeat('a', 40).'/track')->assertNotFound();

        $this->get(route('order.track', $other))
            ->assertOk()
            ->assertSee('Γιώργος Νικολάου')
            ->assertDontSee('Μαρία Παπαδοπούλου');
    }

    public function test_tokens_are_long_random_and_unique(): void
    {
        $tokens = Order::factory()->count(25)->create()->pluck('public_token');

        $this->assertCount(25, $tokens->unique());

        foreach ($tokens as $token) {
            $this->assertSame(40, strlen($token));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $token);
        }
    }

    public function test_tracking_pages_are_disallowed_for_crawlers(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /order/', $robots);
        $this->assertStringContainsString('Disallow: /admin', $robots);
    }
}
