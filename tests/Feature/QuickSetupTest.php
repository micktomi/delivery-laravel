<?php

namespace Tests\Feature;

use App\Actions\ApplyQuickSetupPreset;
use App\Enums\QuickSetupPreset;
use App\Enums\SelectionType;
use App\Filament\Pages\QuickSetup;
use App\Models\Category;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Γρήγορο Στήσιμο" is provisioning-only: it must produce nothing but
 * ordinary Category/OptionGroup/OptionValue rows and the existing
 * category-level template pivot. Every test here is really checking that —
 * there is no business_type anywhere, and once applied the data is
 * indistinguishable from an operator having typed it in by hand.
 */
class QuickSetupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_grill_house_preset_creates_the_expected_categories_and_option_groups_on_an_empty_catalogue(): void
    {
        app(ApplyQuickSetupPreset::class)->execute(QuickSetupPreset::GrillHouse);

        $this->assertSame(
            ['Σουβλάκια', 'Μερίδες', 'Σαλάτες', 'Ορεκτικά', 'Αναψυκτικά'],
            Category::orderBy('sort_order')->pluck('name')->all(),
        );

        $this->assertSame(
            ['Ψήσιμο', 'Πίτα', 'Σάλτσα', 'Extras'],
            OptionGroup::orderBy('sort_order')->pluck('name')->all(),
        );

        // Every category is a real, usable category — not a business_type
        // marker: active, generic fields only.
        $this->assertSame(0, Category::where('is_active', false)->count());
    }

    public function test_grill_house_preset_creates_groups_with_the_specified_selection_required_and_values(): void
    {
        app(ApplyQuickSetupPreset::class)->execute(QuickSetupPreset::GrillHouse);

        $doneness = OptionGroup::where('name', 'Ψήσιμο')->firstOrFail();
        $this->assertTrue($doneness->is_required);
        $this->assertSame(SelectionType::Single, $doneness->selection);
        $this->assertNull($doneness->max_select);
        $this->assertSame(
            ['Μέτριο', 'Καλοψημένο'],
            $doneness->optionValues()->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame('Μέτριο', $doneness->optionValues()->where('is_default', true)->firstOrFail()->name);

        $bread = OptionGroup::where('name', 'Πίτα')->firstOrFail();
        $this->assertFalse($bread->is_required);
        $this->assertSame(SelectionType::Single, $bread->selection);

        $sauce = OptionGroup::where('name', 'Σάλτσα')->firstOrFail();
        $this->assertFalse($sauce->is_required);
        $this->assertSame(
            ['Τζατζίκι', 'Σως', 'Χωρίς'],
            $sauce->optionValues()->orderBy('sort_order')->pluck('name')->all(),
        );
        $this->assertSame('Χωρίς', $sauce->optionValues()->where('is_default', true)->firstOrFail()->name);

        $extras = OptionGroup::where('name', 'Extras')->firstOrFail();
        $this->assertFalse($extras->is_required);
        $this->assertSame(SelectionType::Multi, $extras->selection);
        $this->assertEquals(0.50, $extras->optionValues()->where('name', 'Τυρί')->firstOrFail()->price_delta);
        $this->assertEquals(0.80, $extras->optionValues()->where('name', 'Μπέικον')->firstOrFail()->price_delta);
        $this->assertEquals(1.00, $extras->optionValues()->where('name', 'Πατάτες')->firstOrFail()->price_delta);
    }

    public function test_grill_house_preset_attaches_its_option_groups_to_the_meat_categories_as_templates(): void
    {
        app(ApplyQuickSetupPreset::class)->execute(QuickSetupPreset::GrillHouse);

        $souvlakia = Category::where('name', 'Σουβλάκια')->firstOrFail();
        $merides = Category::where('name', 'Μερίδες')->firstOrFail();
        $salates = Category::where('name', 'Σαλάτες')->firstOrFail();

        $this->assertSame(
            ['Ψήσιμο', 'Πίτα', 'Σάλτσα', 'Extras'],
            $souvlakia->optionGroups()->orderByPivot('sort_order')->pluck('name')->all(),
        );
        $this->assertSame(
            ['Ψήσιμο', 'Πίτα', 'Σάλτσα', 'Extras'],
            $merides->optionGroups()->orderByPivot('sort_order')->pluck('name')->all(),
        );
        // Σαλάτες gets no meat-specific option groups — the template
        // relationship is deliberately not blanket-applied to every category.
        $this->assertSame([], $salates->optionGroups()->pluck('name')->all());
    }

    public function test_restaurant_preset_creates_the_expected_categories_and_option_groups_on_an_empty_catalogue(): void
    {
        app(ApplyQuickSetupPreset::class)->execute(QuickSetupPreset::Restaurant);

        $this->assertSame(
            ['Ορεκτικά', 'Σαλάτες', 'Κυρίως', 'Ζυμαρικά', 'Επιδόρπια', 'Αναψυκτικά'],
            Category::orderBy('sort_order')->pluck('name')->all(),
        );

        $this->assertSame(
            ['Ψήσιμο', 'Συνοδευτικό', 'Extra sauce'],
            OptionGroup::orderBy('sort_order')->pluck('name')->all(),
        );
    }

    public function test_restaurant_preset_creates_groups_with_the_specified_selection_required_and_values(): void
    {
        app(ApplyQuickSetupPreset::class)->execute(QuickSetupPreset::Restaurant);

        $doneness = OptionGroup::where('name', 'Ψήσιμο')->firstOrFail();
        $this->assertTrue($doneness->is_required);
        $this->assertSame(
            ['Σενιάν', 'Μέτριο', 'Καλοψημένο'],
            $doneness->optionValues()->orderBy('sort_order')->pluck('name')->all(),
        );

        $side = OptionGroup::where('name', 'Συνοδευτικό')->firstOrFail();
        $this->assertTrue($side->is_required);
        $this->assertSame(
            ['Πατάτες', 'Ρύζι', 'Λαχανικά'],
            $side->optionValues()->orderBy('sort_order')->pluck('name')->all(),
        );

        $sauce = OptionGroup::where('name', 'Extra sauce')->firstOrFail();
        $this->assertFalse($sauce->is_required);
        $this->assertSame(
            ['Πιπεριού', 'Μανιταριών', 'Χωρίς'],
            $sauce->optionValues()->orderBy('sort_order')->pluck('name')->all(),
        );
    }

    public function test_restaurant_preset_attaches_its_option_groups_only_to_the_mains_category(): void
    {
        app(ApplyQuickSetupPreset::class)->execute(QuickSetupPreset::Restaurant);

        $kyrios = Category::where('name', 'Κυρίως')->firstOrFail();
        $orektika = Category::where('name', 'Ορεκτικά')->firstOrFail();

        $this->assertSame(
            ['Ψήσιμο', 'Συνοδευτικό', 'Extra sauce'],
            $kyrios->optionGroups()->orderByPivot('sort_order')->pluck('name')->all(),
        );
        $this->assertSame([], $orektika->optionGroups()->pluck('name')->all());
    }

    public function test_refuses_a_catalogue_that_already_has_categories(): void
    {
        Category::create(['name' => 'Υπάρχουσα', 'slug' => 'existing', 'sort_order' => 0, 'is_active' => true]);

        $this->expectException(ValidationException::class);

        app(ApplyQuickSetupPreset::class)->execute(QuickSetupPreset::GrillHouse);

        $this->assertSame(1, Category::count());
    }

    public function test_refuses_a_catalogue_that_already_has_option_groups_even_with_no_categories(): void
    {
        OptionGroup::create(['name' => 'Κάτι', 'selection' => SelectionType::Single->value, 'is_required' => false, 'sort_order' => 0]);

        $this->expectException(ValidationException::class);

        app(ApplyQuickSetupPreset::class)->execute(QuickSetupPreset::GrillHouse);
    }

    public function test_running_the_same_preset_twice_is_refused_and_creates_no_duplicates(): void
    {
        app(ApplyQuickSetupPreset::class)->execute(QuickSetupPreset::GrillHouse);
        $categoryCountAfterFirst = Category::count();
        $groupCountAfterFirst = OptionGroup::count();
        $valueCountAfterFirst = OptionValue::count();

        try {
            app(ApplyQuickSetupPreset::class)->execute(QuickSetupPreset::GrillHouse);
            $this->fail('Expected the second run to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('preset', $e->validator->errors()->toArray());
        }

        $this->assertSame($categoryCountAfterFirst, Category::count());
        $this->assertSame($groupCountAfterFirst, OptionGroup::count());
        $this->assertSame($valueCountAfterFirst, OptionValue::count());
    }

    public function test_no_business_type_column_exists_anywhere_the_preset_touches(): void
    {
        $this->assertFalse(Schema::hasColumn('categories', 'business_type'));
        $this->assertFalse(Schema::hasColumn('products', 'business_type'));
        $this->assertFalse(Schema::hasColumn('option_groups', 'business_type'));
        $this->assertFalse(Schema::hasColumn('option_values', 'business_type'));
        $this->assertFalse(Schema::hasColumn('store_settings', 'business_type'));
    }

    public function test_transaction_rolls_back_everything_if_a_later_step_fails(): void
    {
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'option_values') && str_contains($query->sql, 'insert')
                && in_array('Πατάτες', $query->bindings, true)) {
                throw new \RuntimeException('forced failure for test');
            }
        });

        try {
            app(ApplyQuickSetupPreset::class)->execute(QuickSetupPreset::GrillHouse);
            $this->fail('Expected the forced failure to propagate out of execute().');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced failure for test', $e->getMessage());
        }

        // Everything from this run — including the categories and the first
        // three option groups, all created before the forced failure — must
        // have been rolled back as one transaction.
        $this->assertSame(0, Category::count());
        $this->assertSame(0, OptionGroup::count());
        $this->assertSame(0, OptionValue::count());
    }

    public function test_admin_page_applies_the_preset_and_reports_what_it_created(): void
    {
        Livewire::actingAs($this->admin())
            ->test(QuickSetup::class)
            ->set('data.preset', QuickSetupPreset::Restaurant->value)
            ->call('apply');

        $this->assertSame(6, Category::count());
        $this->assertSame(3, OptionGroup::count());
    }

    public function test_admin_page_refuses_when_catalogue_is_not_empty(): void
    {
        Category::create(['name' => 'Υπάρχουσα', 'slug' => 'existing', 'sort_order' => 0, 'is_active' => true]);

        Livewire::actingAs($this->admin())
            ->test(QuickSetup::class)
            ->set('data.preset', QuickSetupPreset::GrillHouse->value)
            ->call('apply');

        $this->assertSame(1, Category::count());
    }

    public function test_apply_action_requires_confirmation_before_running(): void
    {
        $component = Livewire::actingAs($this->admin())
            ->test(QuickSetup::class)
            ->set('data.preset', QuickSetupPreset::GrillHouse->value)
            ->mountAction('apply');

        // Mounting the action alone (what a click does before the confirm
        // dialog is accepted) must not have run it yet.
        $this->assertSame(0, Category::count());

        $component->callMountedAction();

        $this->assertSame(5, Category::count());
    }

    public function test_non_admin_cannot_reach_the_quick_setup_page(): void
    {
        $staff = User::factory()->create(['is_admin' => false]);

        $this->actingAs($staff)->get('/admin/quick-setup')->assertForbidden();
    }
}
