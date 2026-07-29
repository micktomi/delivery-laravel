<?php

namespace App\Observers;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/**
 * There is deliberately no `saved` hook re-encoding the photo here.
 *
 * It used to exist, and it broke uploads. The encoder renames the file
 * (.jpg -> .webp) and deletes the source, but Filament's FileUpload keeps the
 * path it just wrote in the Livewire component's own state, and the edit page
 * does not redirect after saving. So the field was left pointing at a file the
 * observer had already deleted: FilePond hung fetching a 404 preview, and the
 * next hydration silently dropped the missing path from the state — which
 * meant the following save wrote `image = null`, and `updated()` below then
 * deleted the real photo too.
 *
 * Uploads are now stored exactly as Filament wrote them, whatever the format.
 * Converting to WebP is a separate, explicit step run outside any request:
 * `php artisan products:reencode-images`.
 */
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
     * A null image means the product simply renders without a photo — there is
     * no placeholder file — so both hooks below only ever touch uploaded files.
     *
     * Only ever the path the row has just stopped pointing at, never the one it
     * points at now: `wasChanged` is what keeps a save that left the photo
     * alone from deleting it. The re-encode command does not come through here
     * at all — it saves quietly and manages its own source file — so this is
     * purely about a replaced or cleared upload.
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
