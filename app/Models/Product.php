<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    protected $fillable = ['category_id', 'name', 'description', 'image', 'base_price', 'is_available', 'sort_order'];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'is_available' => 'boolean',
        ];
    }

    /**
     * Null means the product has no image at all — there is no default and no
     * placeholder, so the caller renders no image element rather than a
     * broken one.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => filled($this->image)
            ? Storage::disk('public')->url($this->image)
            : null);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(OptionGroup::class, 'product_option_group')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }
}
