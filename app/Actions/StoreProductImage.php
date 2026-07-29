<?php

namespace App\Actions;

use Filament\Forms\Components\BaseFileUpload;
use Illuminate\Support\Facades\Log;
use League\Flysystem\UnableToCheckFileExistence;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Filament's `saveUploadedFileUsing` callback: the one place a product photo
 * becomes a stored file.
 *
 * The conversion has to happen *here*, before the component records the path,
 * rather than afterwards in a model hook. Filament writes whatever this method
 * returns into the FileUpload's own Livewire state and into the column, and it
 * checks on every hydration that the path still exists — so a photo renamed or
 * deleted after this point strands the field, which is exactly the bug this
 * replaces. Convert first, name the result once, and the column, the component
 * state and the disk cannot disagree.
 *
 * Returns null in exactly one case: the temp file is no longer there to store,
 * which is the one situation where there is genuinely nothing to record. Every
 * other failure falls back to storing the upload unconverted. Filament reads
 * null as "this upload produced no file", drops the entry from the state, and
 * — see BaseFileUpload::saveUploadedFiles() — skips the `$file->delete()` that
 * reaps the Livewire temp file, so a null on any recoverable error would both
 * lose the photo and leak a temp file.
 */
class StoreProductImage
{
    public function __construct(private EncodeProductImage $encoder) {}

    public function store(TemporaryUploadedFile $file, BaseFileUpload $component): ?string
    {
        // Mirrors the guard the default callback opens with. A vanished temp
        // file cannot be stored under any fallback, and there is no temp file
        // left to leak by returning null here.
        try {
            if (! $file->exists()) {
                return null;
            }
        } catch (UnableToCheckFileExistence) {
            return null;
        }

        $name = $component->getUploadedFileNameForStorage($file);
        $directory = trim($component->getDirectory() ?? '', '/');

        $webp = $this->encoder->encodeBinary((string) $file->get());

        if ($webp === null) {
            Log::warning('product.image.stored_unconverted', [
                'name' => $name,
                'reason' => function_exists('imagewebp')
                    ? 'GD could not decode the upload.'
                    : 'The GD extension is missing or was built without WebP support.',
            ]);

            return $this->storeVerbatim($file, $component, $directory, $name);
        }

        $path = $this->join($directory, pathinfo($name, PATHINFO_FILENAME).'.webp');

        // Returning a path the disk does not actually have would strand the
        // field just as badly as deleting the file later, so a failed write
        // falls back to keeping the upload rather than reporting success.
        if (! $component->getDisk()->put($path, $webp)) {
            Log::error('product.image.write_failed', [
                'name' => $name,
                'path' => $path,
            ]);

            return $this->storeVerbatim($file, $component, $directory, $name);
        }

        return $path;
    }

    /**
     * The upload exactly as it arrived, under the name Filament chose for it —
     * the same thing the default callback does.
     */
    private function storeVerbatim(
        TemporaryUploadedFile $file,
        BaseFileUpload $component,
        string $directory,
        string $name,
    ): string {
        $method = $component->getVisibility() === 'public' ? 'storePubliclyAs' : 'storeAs';

        return $file->{$method}($directory, $name, $component->getDiskName());
    }

    private function join(string $directory, string $name): string
    {
        return $directory === '' ? $name : $directory.'/'.$name;
    }
}
