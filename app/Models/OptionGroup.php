<?php

namespace App\Models;

use App\Enums\SelectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OptionGroup extends Model
{
    protected $fillable = ['name', 'selection', 'is_required', 'min_select', 'max_select', 'sort_order'];

    protected function casts(): array
    {
        return [
            'selection' => SelectionType::class,
            'is_required' => 'boolean',
        ];
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
