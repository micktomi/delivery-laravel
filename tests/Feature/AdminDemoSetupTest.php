<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\SelectionType;
use App\Filament\Resources\CategoryResource\Pages\CreateCategory;
use App\Filament\Resources\CategoryResource\RelationManagers\OptionGroupsRelationManager as CategoryOptionGroupsRelationManager;
use App\Filament\Resources\OptionGroupResource\Pages\CreateOptionGroup;
use App\Filament\Resources\OptionGroupResource\Pages\EditOptionGroup;
use App\Filament\Resources\OptionGroupResource\RelationManagers\OptionValuesRelationManager;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Livewire\CheckoutPage;
use App\Livewire\MenuPage;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proves Task 5's admin work end to end for two non-coffee catalogues, using
 * only the Filament resources/relation managers an operator would actually
 * click through — no direct catalogue seeding. The point is not the specific
 * businesses; it is that the generic admin/data model (categories, products,
 * option groups/values, the Task 3 dependency metadata) handles them without
 * any business_type or runtime branching, exactly as it handles coffee.
 */
class AdminDemoSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function createCategory(string $name, string $slug, int $sortOrder = 0): Category
    {
        Livewire::actingAs($this->admin)
            ->test(CreateCategory::class)
            ->fillForm(['name' => $name, 'slug' => $slug, 'sort_order' => $sortOrder])
            ->call('create')
            ->assertHasNoFormErrors();

        return Category::where('slug', $slug)->firstOrFail();
    }

    /** @return array{0: OptionGroup, 1: array<string, OptionValue>} */
    private function createOptionGroup(string $name, SelectionType $selection, bool $required, array $values, ?int $maxSelect = null): array
    {
        Livewire::actingAs($this->admin)
            ->test(CreateOptionGroup::class)
            ->fillForm(array_filter([
                'name' => $name,
                'selection' => $selection->value,
                'is_required' => $required,
                'max_select' => $maxSelect,
            ], fn ($v) => $v !== null))
            ->call('create')
            ->assertHasNoFormErrors();

        $group = OptionGroup::where('name', $name)->firstOrFail();
        $createdValues = [];

        foreach ($values as $index => [$valueName, $priceDelta, $isDefault]) {
            Livewire::actingAs($this->admin)
                ->test(OptionValuesRelationManager::class, ['ownerRecord' => $group, 'pageClass' => EditOptionGroup::class])
                ->callTableAction('create', data: [
                    'name' => $valueName,
                    'price_delta' => $priceDelta,
                    'is_default' => $isDefault,
                    'sort_order' => $index,
                ]);
            $createdValues[$valueName] = OptionValue::where('option_group_id', $group->id)->where('name', $valueName)->firstOrFail();
        }

        return [$group->fresh(), $createdValues];
    }

    private function attachGroupToCategory(Category $category, OptionGroup $group, int $sortOrder): void
    {
        Livewire::actingAs($this->admin)
            ->test(CategoryOptionGroupsRelationManager::class, [
                'ownerRecord' => $category,
                'pageClass' => \App\Filament\Resources\CategoryResource\Pages\EditCategory::class,
            ])
            ->callTableAction('attach', data: ['recordId' => $group->id, 'sort_order' => $sortOrder]);
    }

    private function createProduct(Category $category, string $name, string $price): Product
    {
        Livewire::actingAs($this->admin)
            ->test(CreateProduct::class)
            ->fillForm([
                'category_id' => $category->id,
                'name' => $name,
                'base_price' => $price,
                'is_available' => true,
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return Product::where('name', $name)->firstOrFail();
    }

    private function checkout(): Order
    {
        Livewire::test(CheckoutPage::class)
            ->set('customer_name', 'Μιχάλης')
            ->set('phone', '6912345678')
            ->set('address', 'Δημοκρατίας 42')
            ->set('payment_method', PaymentMethod::Cash->value)
            ->call('submit')
            ->assertHasNoErrors();

        return Order::firstOrFail();
    }

    public function test_grill_house_demo_can_be_configured_entirely_through_the_admin(): void
    {
        // Categories.
        $souvlakia = $this->createCategory('Σουβλάκια', 'souvlakia', 0);
        $this->createCategory('Μερίδες', 'merides', 1);
        $this->createCategory('Σαλάτες', 'salates-grill', 2);
        $this->createCategory('Αναψυκτικά', 'anapsyktika', 3);
        $this->assertSame(4, Category::count());

        // Option groups + values.
        [$doneness] = $this->createOptionGroup('Ψήσιμο', SelectionType::Single, true, [
            ['Μέτριο', 0, true],
            ['Καλοψημένο', 0, false],
        ]);
        [$bread] = $this->createOptionGroup('Πίτα', SelectionType::Single, false, [
            ['Χωριάτικη', 0, true],
            ['Σταρένια', 0, false],
        ]);
        [$sauce] = $this->createOptionGroup('Σάλτσα', SelectionType::Single, false, [
            ['Τζατζίκι', 0, true],
            ['Μουστάρδα', 0, false],
            ['Χωρίς σάλτσα', 0, false],
        ]);
        [$extras] = $this->createOptionGroup('Extras', SelectionType::Multi, false, [
            ['Τυρί', 0.50, false],
            ['Μπέικον', 0.80, false],
            ['Κρεμμύδι', 0.30, false],
        ], maxSelect: 2);

        // Wire the four groups onto the category as defaults, then create the
        // product — ProductObserver::created() copies the category's default
        // option groups onto every new product in it, so this alone is
        // enough for Καλαμάκι Χοιρινό to carry all four.
        $this->attachGroupToCategory($souvlakia, $doneness, 0);
        $this->attachGroupToCategory($souvlakia, $bread, 1);
        $this->attachGroupToCategory($souvlakia, $sauce, 2);
        $this->attachGroupToCategory($souvlakia, $extras, 3);

        $product = $this->createProduct($souvlakia, 'Καλαμάκι Χοιρινό', '2.50');

        $this->assertSame(
            ['Ψήσιμο', 'Πίτα', 'Σάλτσα', 'Extras'],
            $product->optionGroups()->orderByPivot('sort_order')->pluck('name')->all(),
        );

        // A required group left unset is refused, same as any other product.
        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$doneness->id, null)
            ->call('addToCart')
            ->assertHasErrors('options');
        $this->assertSame([], app(CartService::class)->items());

        // A full, valid configuration prices and persists correctly.
        $wellDone = OptionValue::where('option_group_id', $doneness->id)->where('name', 'Καλοψημένο')->firstOrFail();
        $cheese = OptionValue::where('option_group_id', $extras->id)->where('name', 'Τυρί')->firstOrFail();
        $bacon = OptionValue::where('option_group_id', $extras->id)->where('name', 'Μπέικον')->firstOrFail();

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$doneness->id, $wellDone->id)
            ->set('selectedOptions.'.$extras->id, [$cheese->id, $bacon->id])
            ->call('addToCart')
            ->assertDispatched('cart-updated', productId: $product->id);

        $line = app(CartService::class)->items()[0];
        $this->assertEquals(3.80, $line['line_total']); // 2.50 + 0.50 + 0.80

        $order = $this->checkout();
        $item = $order->items()->firstOrFail();
        $this->assertEquals(3.80, $item->line_total);
        $options = collect($item->selected_options);
        $this->assertTrue($options->contains('value', 'Καλοψημένο'));
        $this->assertTrue($options->contains('value', 'Τυρί'));
        $this->assertTrue($options->contains('value', 'Μπέικον'));

        // Trying to select a third Extra is refused server-side (max_select=2),
        // even bypassing the client entirely.
        app(CartService::class)->add([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'base_price' => (float) $product->base_price,
            'selected_options' => OptionValue::where('option_group_id', $extras->id)->get()
                ->push(OptionValue::where('option_group_id', $doneness->id)->where('is_default', true)->firstOrFail())
                ->map(fn (OptionValue $v) => [
                    'option_value_id' => $v->id,
                    'group' => $v->optionGroup->name,
                    'value' => $v->name,
                    'price_delta' => (float) $v->price_delta,
                ])->all(),
            'quantity' => 1,
            'line_total' => (float) $product->base_price,
            'notes' => '',
        ]);

        try {
            app(\App\Actions\CreateOrder::class)->execute([
                'customer_name' => 'Μιχάλης', 'phone' => '6912345678',
                'address' => 'Δημοκρατίας 42', 'payment_method' => PaymentMethod::Cash->value,
            ]);
            $this->fail('Expected the third Extras selection to be refused.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('cart', $e->validator->errors()->toArray());
        }
    }

    public function test_restaurant_demo_can_be_configured_entirely_through_the_admin(): void
    {
        $kyrios = $this->createCategory('Κυρίως', 'kyrios', 2);
        $this->createCategory('Ορεκτικά', 'orektika', 0);
        $this->createCategory('Σαλάτες', 'salates-restaurant', 1);
        $this->createCategory('Επιδόρπια', 'epidorpia', 3);
        $this->assertSame(4, Category::count());

        [$doneness] = $this->createOptionGroup('Ψήσιμο', SelectionType::Single, true, [
            ['Medium', 0, true],
            ['Well done', 0, false],
        ]);
        [$side] = $this->createOptionGroup('Συνοδευτικό', SelectionType::Single, true, [
            ['Πατάτες τηγανητές', 0, true],
            ['Ρύζι', 0, false],
            ['Εποχής λαχανικά', 0.50, false],
        ]);
        [$extraSauce] = $this->createOptionGroup('Extra sauce', SelectionType::Single, false, [
            ['Χωρίς', 0, true],
            ['Pepper sauce', 1.00, false],
        ]);

        $this->attachGroupToCategory($kyrios, $doneness, 0);
        $this->attachGroupToCategory($kyrios, $side, 1);
        $this->attachGroupToCategory($kyrios, $extraSauce, 2);

        $product = $this->createProduct($kyrios, 'Μοσχαρίσια Μπριζόλα', '18.00');

        $this->assertSame(
            ['Ψήσιμο', 'Συνοδευτικό', 'Extra sauce'],
            $product->optionGroups()->orderByPivot('sort_order')->pluck('name')->all(),
        );

        // Both required groups (Ψήσιμο, Συνοδευτικό) are enforced.
        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$side->id, null)
            ->call('addToCart')
            ->assertHasErrors('options');
        $this->assertSame([], app(CartService::class)->items());

        $wellDone = OptionValue::where('option_group_id', $doneness->id)->where('name', 'Well done')->firstOrFail();
        $vegetables = OptionValue::where('option_group_id', $side->id)->where('name', 'Εποχής λαχανικά')->firstOrFail();
        $pepperSauce = OptionValue::where('option_group_id', $extraSauce->id)->where('name', 'Pepper sauce')->firstOrFail();

        Livewire::test(MenuPage::class)
            ->call('openProduct', $product->id)
            ->set('selectedOptions.'.$doneness->id, $wellDone->id)
            ->set('selectedOptions.'.$side->id, $vegetables->id)
            ->set('selectedOptions.'.$extraSauce->id, $pepperSauce->id)
            ->call('addToCart')
            ->assertDispatched('cart-updated', productId: $product->id);

        $line = app(CartService::class)->items()[0];
        $this->assertEquals(19.50, $line['line_total']); // 18.00 + 0.50 + 1.00

        $order = $this->checkout();
        $item = $order->items()->firstOrFail();
        $this->assertEquals(19.50, $item->line_total);
        $options = collect($item->selected_options);
        $this->assertTrue($options->contains('value', 'Well done'));
        $this->assertTrue($options->contains('value', 'Εποχής λαχανικά'));
        $this->assertTrue($options->contains('value', 'Pepper sauce'));
    }
}
