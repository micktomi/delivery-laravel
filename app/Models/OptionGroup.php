<?php

namespace App\Models;

use App\Enums\SelectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OptionGroup extends Model
{
    protected $fillable = [
        'name', 'selection', 'is_required', 'min_select', 'max_select', 'sort_order',
        'hidden_when_option_value_id', 'combine_display_with_option_group_id',
    ];

    protected function casts(): array
    {
        return [
            'selection' => SelectionType::class,
            'is_required' => 'boolean',
            'hidden_when_option_value_id' => 'integer',
            'combine_display_with_option_group_id' => 'integer',
        ];
    }

    /**
     * True when $selectedValueIds (every option value currently picked
     * anywhere on the product, across all groups) contains this group's
     * gating value — e.g. an optional add-on group once a "plain" choice
     * elsewhere makes it moot. Driven entirely by hidden_when_option_value_id;
     * group/option names and business vocabulary never enter this check.
     *
     * @param  array<int, int>  $selectedValueIds
     */
    public function isHiddenGiven(array $selectedValueIds): bool
    {
        return $this->hidden_when_option_value_id !== null
            && in_array($this->hidden_when_option_value_id, $selectedValueIds, true);
    }

    public function optionValues(): HasMany
    {
        return $this->hasMany(OptionValue::class)->orderBy('sort_order');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_option_group')
            ->withPivot('sort_order');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_option_group')
            ->withPivot('sort_order');
    }
}
