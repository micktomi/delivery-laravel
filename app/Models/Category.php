<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class)->orderBy('sort_order');
    }

    /**
     * A category earns the image layout only when every one of its products has
     * an image. One product short and the whole category stays on the text
     * list, so a half-illustrated grid can never happen.
     *
     * Reads only an already eager-loaded relation: answering this per category
     * mid-render would otherwise fire a query each time. Unloaded means unknown,
     * and unknown falls back to the text list, which is the default anyway.
     * Deliberately kept out of $appends so it never serialises.
     */
    protected function usesImages(): Attribute
    {
        return Attribute::get(function (): bool {
            if (! $this->relationLoaded('products')) {
                return false;
            }

            return $this->products->isNotEmpty()
                && $this->products->every(fn (Product $product) => filled($product->image));
        });
    }

    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(OptionGroup::class, 'category_option_group')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }
}
