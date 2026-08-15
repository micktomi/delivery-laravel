<?php

namespace Tests\Feature;

use App\Actions\CreateOrder;
use App\Enums\PaymentMethod;
use App\Enums\SelectionType;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Livewire\CheckoutPage;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionMediumRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_category_is_rechecked_when_the_order_is_submitted(): void
    {
        $product = $this->product();
        $this->addCartLine($product);

        $product->category->update(['is_active' => false]);

        $this->assertCheckoutIsRejected();
    }

    public function test_deleted_option_value_is_rejected_instead_of_using_its_stale_snapshot(): void
    {
        [$product, $group, $value] = $this->productWithOption();
        $this->addCartLineWithOptions($product, [$this->optionSnapshot($group, $value)]);

        $value->delete();

        $this->assertCheckoutIsRejected();
    }

    public function test_option_from_a_group_detached_from_the_product_is_rejected(): void
    {
        [$product, $group, $value] = $this->productWithOption();
        $this->addCartLineWithOptions($product, [$this->optionSnapshot($group, $value)]);

        $product->optionGroups()->detach($group->id);

        $this->assertCheckoutIsRejected();
    }

    public function test_newly_required_option_group_is_rechecked_at_submit(): void
    {
        [$product, $group] = $this->productWithOption(required: false);
        $this->addCartLine($product);

        $group->update(['is_required' => true]);

        $this->assertCheckoutIsRejected();
    }

    public function test_increased_minimum_option_selection_is_rechecked_at_submit(): void
    {
        [$product, $group, $value] = $this->productWithOption(
            selection: SelectionType::Multi,
            required: true,
            min: 1,
            max: 3,
        );
        $this->addCartLineWithOptions($product, [$this->optionSnapshot($group, $value)]);

        $group->update(['min_select' => 2]);

        $this->assertCheckoutIsRejected();
    }

    public function test_reduced_maximum_option_selection_is_rechecked_at_submit(): void
    {
        [$product, $group, $first] = $this->productWithOption(
            selection: SelectionType::Multi,
            required: false,
            max: 2,
        );
        $second = $this->optionValue($group, 'Κανέλα', '0.20');
        $this->addCartLineWithOptions($product, [
            $this->optionSnapshot($group, $first),
            $this->optionSnapshot($group, $second),
        ], lineTotal: 3.70);

        $group->update(['max_select' => 1]);

        $this->assertCheckoutIsRejected();
    }

    public function test_current_option_metadata_and_price_replace_the_stale_snapshot(): void
    {
        [$product, $group, $value] = $this->productWithOption();
        $this->addCartLineWithOptions($product, [[
            'option_value_id' => $value->id,
            'group' => 'STALE GROUP',
            'value' => 'STALE VALUE',
            'price_delta' => -99.00,
        ]], lineTotal: 3.50);

        $order = app(CreateOrder::class)->execute($this->checkoutData());
        $stored = $order->items->first()->selected_options[0];

        $this->assertSame($group->name, $stored['group']);
        $this->assertSame($value->name, $stored['value']);
        $this->assertEquals(0.70, $stored['price_delta']);
        $this->assertEquals(3.50, $order->total);
    }

    public function test_legacy_option_snapshot_without_an_option_value_id_remains_supported(): void
    {
        $product = $this->product();
        $legacyOption = [
            'group' => 'Παλιά επιλογή',
            'value' => 'Διπλός',
            'price_delta' => 0.40,
        ];
        $this->addCartLineWithOptions($product, [$legacyOption], lineTotal: 3.20);

        $order = app(CreateOrder::class)->execute($this->checkoutData());

        $this->assertSame($legacyOption, $order->items->first()->selected_options[0]);
        $this->assertEquals(3.20, $order->total);
    }

    public function test_checkout_rejects_an_address_longer_than_the_existing_column(): void
    {
        $this->addCartLine($this->product());

        Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', str_repeat('α', 256))
            ->set('payment_method', PaymentMethod::Cash->value)
            ->call('submit')
            ->assertHasErrors(['address' => 'max']);

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_admin_rejects_a_negative_product_base_price(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreateProduct::class)
            ->fillForm([
                'category_id' => $this->category()->id,
                'name' => 'Invalid price',
                'base_price' => '-0.01',
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['base_price']);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_pricing_rejects_only_a_negative_final_unit_price(): void
    {
        $pricing = app(PricingService::class);

        $this->assertSame(1.50, $pricing->lineTotal(2.00, [-0.50], 1));

        $this->expectException(InvalidArgumentException::class);
        $pricing->lineTotal(2.00, [-2.01], 1);
    }

    public function test_negative_final_line_price_cannot_be_persisted(): void
    {
        [$product, $group, $value] = $this->productWithOption(delta: '-3.00');
        $this->addCartLineWithOptions(
            $product,
            [$this->optionSnapshot($group, $value)],
            lineTotal: -0.20,
        );

        $this->expectException(ValidationException::class);

        try {
            app(CreateOrder::class)->execute($this->checkoutData());
        } finally {
            $this->assertDatabaseCount('orders', 0);
            $this->assertDatabaseCount('order_items', 0);
        }
    }

    private function assertCheckoutIsRejected(): void
    {
        try {
            app(CreateOrder::class)->execute($this->checkoutData());
            $this->fail('Expected the stale cart to be rejected.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->validator->errors()->first('cart'));
        }

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
    }

    private function category(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'kafedes'],
            ['name' => 'Καφέδες', 'sort_order' => 0, 'is_active' => true],
        );
    }

    private function product(string $price = '2.80'): Product
    {
        return Product::create([
            'category_id' => $this->category()->id,
            'name' => 'Freddo Espresso',
            'base_price' => $price,
            'is_available' => true,
            'sort_order' => 0,
        ]);
    }

    private function productWithOption(
        SelectionType $selection = SelectionType::Single,
        bool $required = true,
        ?int $min = null,
        ?int $max = null,
        string $delta = '0.70',
    ): array {
        $product = $this->product();
        $group = OptionGroup::create([
            'name' => 'Μέγεθος / Δόση',
            'selection' => $selection->value,
            'is_required' => $required,
            'min_select' => $min,
            'max_select' => $max,
            'sort_order' => 0,
        ]);
        $value = $this->optionValue($group, 'Διπλός', $delta);
        $product->optionGroups()->attach($group->id, ['sort_order' => 0]);

        return [$product, $group, $value];
    }

    private function optionValue(OptionGroup $group, string $name, string $delta): OptionValue
    {
        return OptionValue::create([
            'option_group_id' => $group->id,
            'name' => $name,
            'price_delta' => $delta,
            'is_default' => false,
            'sort_order' => 0,
        ]);
    }

    private function optionSnapshot(OptionGroup $group, OptionValue $value): array
    {
        return [
            'option_value_id' => $value->id,
            'group' => $group->name,
            'value' => $value->name,
            'price_delta' => (float) $value->price_delta,
        ];
    }

    private function addCartLine(Product $product): void
    {
        $this->addCartLineWithOptions($product, [], (float) $product->base_price);
    }

    private function addCartLineWithOptions(Product $product, array $options, float $lineTotal = 3.50): void
    {
        app(CartService::class)->replace([[
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => (float) $product->base_price,
            'selected_options' => $options,
            'quantity' => 1,
            'line_total' => $lineTotal,
            'notes' => '',
        ]]);
    }

    private function checkoutData(): array
    {
        return [
            'customer_name' => 'Μιχάλης',
            'phone' => '6912345678',
            'address' => 'Δημοκρατίας 42',
            'floor_bell' => null,
            'notes' => null,
            'payment_method' => PaymentMethod::Cash->value,
        ];
    }
}
