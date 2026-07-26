<?php

namespace App\Observers;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;

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

    /**
     * A null image means the product falls back to default artwork shipped with
     * the repo, so both hooks below only ever touch genuinely uploaded files.
     */
    public function updated(Product $product): void
    {
        if (! $product->wasChanged('image')) {
            return;
        }

        $this->deleteUploadedImage($product->getOriginal('image'));
    }

    public function deleted(Product $product): void
    {
        $this->deleteUploadedImage($product->image);
    }

    private function deleteUploadedImage(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
