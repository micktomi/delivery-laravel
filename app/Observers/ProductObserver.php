<?php

namespace App\Observers;

use App\Actions\EncodeProductImage;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

class ProductObserver
{
    public function __construct(private EncodeProductImage $encoder) {}

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
     * Normalise whatever the browser uploaded into a 600px WebP. This runs on
     * every write path — Filament, the seeder, tinker — which is the point:
     * the client-side FilePond transform is a bandwidth optimisation, not a
     * guarantee, and nothing may reach disk without passing through here.
     *
     * Laravel fires `updated` before `saved`, so by this point the replaced
     * file is already gone and there is only one image left to encode.
     *
     * Called unconditionally on purpose. No guard here can be both complete and
     * non-repeating — `wasChanged('image')` is already false when `saved` fires
     * on a create, and `wasRecentlyCreated` never resets — and any guard at all
     * strands the legacy case: a product still holding a pre-existing .jpg,
     * saved with nothing changed, would never convert. The action early-returns
     * on an already-normalised file for the cost of one header read, which is
     * nothing against a rare admin save.
     */
    public function saved(Product $product): void
    {
        $this->encoder->execute($product);
    }

    /**
     * A null image means the product simply renders without a photo — there is
     * no placeholder file — so both hooks below only ever touch uploaded files.
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
