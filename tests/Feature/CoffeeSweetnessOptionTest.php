<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Enums\PaymentMethod;
use App\Livewire\CheckoutPage;
use App\Livewire\MenuPage;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\OptionsPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CoffeeSweetnessOptionTest extends TestCase
{
    use RefreshDatabase;

    private function sweetnessValue(string $name): OptionValue
    {
        $group = OptionGroup::where('name', 'Ζάχαρη')->firstOrFail();

        return OptionValue::where('option_group_id', $group->id)->where('name', $name)->firstOrFail();
    }

    private function sweetenerValue(string $name): OptionValue
    {
        $group = OptionGroup::where('name', 'Γλυκαντικό')->firstOrFail();

        return OptionValue::where('option_group_id', $group->id)->where('name', $name)->firstOrFail();
    }

    // 1 & 2. Canonical presentation.
    public function test_format_collapses_default_sweetener_into_the_sweetness_label(): void
    {
        $options = [
            ['group' => 'Ζάχαρη', 'value' => 'Μέτριος', 'price_delta' => 0.0],
            ['group' => 'Γλυκαντικό', 'value' => 'Ζάχαρη', 'price_delta' => 0.0],
        ];

        $this->assertSame('Μέτριος', OptionsPresenter::format($options));
    }

    public function test_format_appends_a_non_default_sweetener_to_the_sweetness_label(): void
    {
        $options = [
            ['group' => 'Ζάχαρη', 'value' => 'Μέτριος', 'price_delta' => 0.0],
            ['group' => 'Γλυκαντικό', 'value' => 'Στέβια', 'price_delta' => 0.0],
        ];

        $this->assertSame('Μέτριος με Στέβια', OptionsPresenter::format($options));

        $options = [
            ['group' => 'Ζάχαρη', 'value' => 'Γλυκός', 'price_delta' => 0.0],
            ['group' => 'Γλυκαντικό', 'value' => 'Ζαχαρίνη', 'price_delta' => 0.0],
        ];

        $this->assertSame('Γλυκός με Ζαχαρίνη', OptionsPresenter::format($options));
    }

    // Brown sugar is just another non-default sweetener: the existing
    // name-driven formatter needs no code change to handle it.
    public function test_format_appends_brown_sugar_to_the_sweetness_label(): void
    {
        $options = [
            ['group' => 'Ζάχαρη', 'value' => 'Μέτριος', 'price_delta' => 0.0],
            ['group' => 'Γλυκαντικό', 'value' => 'Καστανή ζάχαρη', 'price_delta' => 0.0],
        ];

        $this->assertSame('Μέτριος με Καστανή ζάχαρη', OptionsPresenter::format($options));
    }

    public function test_brown_sugar_option_value_exists_on_the_sweetener_group(): void
    {
        $this->seed();

        $group = OptionGroup::where('name', 'Γλυκαντικό')->firstOrFail();

        $this->assertDatabaseHas('option_values', [
            'option_group_id' => $group->id,
            'name' => 'Καστανή ζάχαρη',
        ]);

        // Re-running the seeder must not duplicate the row.
        $this->seed(\Database\Seeders\BrownSugarSweetenerSeeder::class);
        $this->assertSame(1, OptionValue::where('option_group_id', $group->id)
            ->where('name', 'Καστανή ζάχαρη')->count());
    }

    // 3. An inconsistent "Σκέτος + sweetener" combination never survives formatting.
    public function test_format_drops_any_sweetener_once_the_coffee_is_plain(): void
    {
        $options = [
            ['group' => 'Ζάχαρη', 'value' => 'Σκέτος', 'price_delta' => 0.0],
            ['group' => 'Γλυκαντικό', 'value' => 'Στέβια', 'price_delta' => 0.0],
        ];

        $this->assertSame('Σκέτος', OptionsPresenter::format($options));
        $this->assertSame(
            [['group' => 'Ζάχαρη', 'value' => 'Σκέτος', 'price_delta' => 0.0]],
            OptionsPresenter::canonicalize($options),
        );
    }

    // 6. Unrelated options are untouched.
    public function test_format_keeps_unrelated_options_around_the_sweetness_pair(): void
    {
        $options = [
            ['group' => 'Μέγεθος / Δόση', 'value' => 'Διπλός', 'price_delta' => 0.7],
            ['group' => 'Ζάχαρη', 'value' => 'Μέτριος', 'price_delta' => 0.0],
            ['group' => 'Γλυκαντικό', 'value' => 'Στέβια', 'price_delta' => 0.0],
            ['group' => 'Γάλα', 'value' => 'Φρέσκο', 'price_delta' => 0.0],
        ];

        $this->assertSame('Διπλός · Μέτριος με Στέβια · Φρέσκο', OptionsPresenter::format($options));
    }

    // 3 (server side). A client that submits "Σκέτος + Στέβια" straight to addToCart
    // must not end up with that combination in the stored cart snapshot.
    public function test_add_to_cart_canonicalizes_a_plain_coffee_with_a_stray_sweetener(): void
    {
        $this->seed();

        $product = Product::where('name', 'Freddo Espresso')->firstOrFail();
        $sweetnessGroup = OptionGroup::where('name', 'Ζάχαρη')->firstOrFail();
        $sweetenerGroup = OptionGroup::where('name', 'Γλυκαντικό')->firstOrFail();
        $plain = $this->sweetnessValue('Σκέτος');
        $stevia = $this->sweetenerValue('Στέβια');

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$sweetnessGroup->id, $plain->id)
            ->set('selectedOptions.'.$sweetenerGroup->id, $stevia->id)
            ->call('addToCart');

        $line = app(CartService::class)->items()[0];
        $options = collect($line['selected_options']);

        $this->assertTrue($options->contains('value', 'Σκέτος'));
        $this->assertFalse($options->contains('group', 'Γλυκαντικό'));
    }

    // 3 (server side, defense in depth). Even if an inconsistent snapshot
    // reaches CreateOrder — bypassing the Livewire component entirely — the
    // stored order item is canonicalized rather than trusted as-is.
    public function test_create_order_canonicalizes_a_tampered_plain_plus_sweetener_snapshot(): void
    {
        $this->seed();

        $product = Product::where('name', 'Freddo Espresso')->firstOrFail();
        $sizeGroup = OptionGroup::where('name', 'Μέγεθος / Δόση')->firstOrFail();
        $size = OptionValue::where('option_group_id', $sizeGroup->id)->where('is_default', true)->firstOrFail();
        $plain = $this->sweetnessValue('Σκέτος');
        $stevia = $this->sweetenerValue('Στέβια');

        app(CartService::class)->add([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => (float) $product->base_price,
            'selected_options' => [
                [
                    'option_value_id' => $size->id,
                    'group' => 'Μέγεθος / Δόση',
                    'value' => $size->name,
                    'price_delta' => (float) $size->price_delta,
                ],
                [
                    'option_value_id' => $plain->id,
                    'group' => 'Ζάχαρη',
                    'value' => 'Σκέτος',
                    'price_delta' => 0.0,
                ],
                [
                    'option_value_id' => $stevia->id,
                    'group' => 'Γλυκαντικό',
                    'value' => 'Στέβια',
                    'price_delta' => 0.0,
                ],
            ],
            'quantity' => 1,
            'line_total' => (float) $product->base_price,
            'notes' => '',
        ]);

        $order = app(CreateOrder::class)->execute([
            'customer_name' => 'Μιχάλης',
            'phone' => '6912345678',
            'address' => 'Δημοκρατίας 42',
            'payment_method' => PaymentMethod::Cash->value,
        ]);

        $item = $order->items()->firstOrFail();
        $options = collect($item->selected_options);

        $this->assertTrue($options->contains('value', 'Σκέτος'));
        $this->assertFalse($options->contains('group', 'Γλυκαντικό'));
    }

    // 4 & 5. The modal wires the sweetener group to hide on "Σκέτος" and
    // restore with the safe "Ζάχαρη" default otherwise, driven by name — not id.
    public function test_product_modal_wires_the_sweetener_group_to_the_plain_sweetness_state(): void
    {
        $this->seed();

        $product = Product::where('name', 'Freddo Espresso')->firstOrFail();

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->assertSeeHtml('x-show="!isPlain"')
            ->assertSeeHtml("plainValueName: 'Σκέτος'")
            ->assertSeeHtml("defaultSweetenerValueName: 'Ζάχαρη'");
    }

    // 7. Cart, checkout, tracking and kitchen board all render the same
    // collapsed label through the shared formatter.
    public function test_collapsed_sweetness_label_is_consistent_across_cart_checkout_tracking_and_kitchen(): void
    {
        $this->seed();

        $product = Product::where('name', 'Freddo Espresso')->firstOrFail();
        $sweetnessGroup = OptionGroup::where('name', 'Ζάχαρη')->firstOrFail();
        $sweetenerGroup = OptionGroup::where('name', 'Γλυκαντικό')->firstOrFail();
        $medium = $this->sweetnessValue('Μέτριος');
        $stevia = $this->sweetenerValue('Στέβια');

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$sweetnessGroup->id, $medium->id)
            ->set('selectedOptions.'.$sweetenerGroup->id, $stevia->id)
            ->call('addToCart')
            ->assertSee('Μέτριος με Στέβια')
            ->assertDontSee('Γλυκαντικό: Ζάχαρη');

        Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Cash->value)
            ->call('submit')
            ->assertHasNoErrors();

        $order = Order::firstOrFail();
        $this->assertStringContainsString(
            'Μέτριος με Στέβια',
            OptionsPresenter::format($order->items()->firstOrFail()->selected_options),
        );

        $this->get(route('order.track', $order))
            ->assertOk()
            ->assertSee('Μέτριος με Στέβια')
            ->assertDontSee('Μέτριος · Ζάχαρη');

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('kitchen'))
            ->assertOk()
            ->assertSee('Μέτριος με Στέβια')
            ->assertDontSee('Μέτριος · Ζάχαρη');
    }
}
