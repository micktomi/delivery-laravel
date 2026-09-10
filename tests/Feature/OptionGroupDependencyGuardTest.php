<?php

namespace Tests\Feature;

use App\Enums\SelectionType;
use App\Filament\Resources\OptionGroupResource\Pages\CreateOptionGroup;
use App\Filament\Resources\OptionGroupResource\Pages\EditOptionGroup;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Task 3 dependency fields (hidden_when_option_value_id,
 * combine_display_with_option_group_id) already exclude a group's own
 * values/id from the Filament form's pickers — that is a UI convenience.
 * These tests cover the model-level guard added in OptionGroup::booted(),
 * which rejects a self-reference on every write path, not just the form.
 */
class OptionGroupDependencyGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_rejects_a_group_combining_display_with_itself(): void
    {
        $group = OptionGroup::create([
            'name' => 'Σάλτσα', 'selection' => SelectionType::Single->value,
            'is_required' => false, 'sort_order' => 0,
        ]);

        $this->expectException(ValidationException::class);

        $group->update(['combine_display_with_option_group_id' => $group->id]);
    }

    public function test_model_rejects_a_group_hidden_by_its_own_value(): void
    {
        $group = OptionGroup::create([
            'name' => 'Ψήσιμο', 'selection' => SelectionType::Single->value,
            'is_required' => true, 'sort_order' => 0,
        ]);
        $ownValue = OptionValue::create([
            'option_group_id' => $group->id, 'name' => 'Μέτριο',
            'price_delta' => 0, 'is_default' => true, 'sort_order' => 0,
        ]);

        $this->expectException(ValidationException::class);

        $group->update(['hidden_when_option_value_id' => $ownValue->id]);
    }

    public function test_model_allows_a_real_cross_group_dependency(): void
    {
        $doneness = OptionGroup::create([
            'name' => 'Ψήσιμο', 'selection' => SelectionType::Single->value,
            'is_required' => true, 'sort_order' => 0,
        ]);
        $noSauce = OptionValue::create([
            'option_group_id' => $doneness->id, 'name' => 'Χωρίς σάλτσα',
            'price_delta' => 0, 'is_default' => false, 'sort_order' => 0,
        ]);
        $sauce = OptionGroup::create([
            'name' => 'Σάλτσα', 'selection' => SelectionType::Single->value,
            'is_required' => false, 'sort_order' => 1,
        ]);

        $sauce->update([
            'hidden_when_option_value_id' => $noSauce->id,
            'combine_display_with_option_group_id' => $doneness->id,
        ]);

        $this->assertSame($noSauce->id, $sauce->fresh()->hidden_when_option_value_id);
        $this->assertSame($doneness->id, $sauce->fresh()->combine_display_with_option_group_id);
    }

    public function test_create_form_never_offers_a_brand_new_group_as_its_own_dependency(): void
    {
        $admin = User::factory()->admin()->create();
        $existing = OptionGroup::create([
            'name' => 'Extras', 'selection' => SelectionType::Multi->value,
            'is_required' => false, 'sort_order' => 0,
        ]);

        Livewire::actingAs($admin)
            ->test(CreateOptionGroup::class)
            ->assertFormFieldExists('combine_display_with_option_group_id')
            ->fillForm([
                'name' => 'Σάλτσα',
                'selection' => SelectionType::Single->value,
                'combine_display_with_option_group_id' => $existing->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($existing->id, OptionGroup::where('name', 'Σάλτσα')->firstOrFail()->combine_display_with_option_group_id);
    }

    public function test_edit_form_excludes_the_group_itself_from_both_dependency_pickers(): void
    {
        $admin = User::factory()->admin()->create();
        $group = OptionGroup::create([
            'name' => 'Σάλτσα', 'selection' => SelectionType::Single->value,
            'is_required' => false, 'sort_order' => 0,
        ]);
        $ownValue = OptionValue::create([
            'option_group_id' => $group->id, 'name' => 'Χωρίς σάλτσα',
            'price_delta' => 0, 'is_default' => false, 'sort_order' => 0,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(EditOptionGroup::class, ['record' => $group->getRouteKey()]);

        $groupOptions = $component->instance()->form
            ->getComponent(fn ($c) => $c instanceof \Filament\Forms\Components\Select && $c->getName() === 'combine_display_with_option_group_id')
            ->getOptions();
        $valueOptions = $component->instance()->form
            ->getComponent(fn ($c) => $c instanceof \Filament\Forms\Components\Select && $c->getName() === 'hidden_when_option_value_id')
            ->getOptions();

        $this->assertArrayNotHasKey($group->id, $groupOptions);
        $this->assertArrayNotHasKey($ownValue->id, $valueOptions);
    }
}
