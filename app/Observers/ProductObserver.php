<?php

namespace App\Observers;

use App\Models\Product;

class ProductObserver
{
    public function created(Product $product): void
    {
        $product->load('category.optionGroups');

        $groups = $product->category->optionGroups;

        if ($groups->isNotEmpty()) {
            $syncData = $groups->mapWithKeys(fn ($group) => [
                $group->id => ['sort_order' => $group->pivot->sort_order],
            ])->all();

            $product->optionGroups()->sync($syncData);
        }
    }
}
