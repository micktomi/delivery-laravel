<?php

namespace App\Actions;

use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EncodeProductImage
{
    /**
     * Every stored photo ends up a square of this edge. The storefront paints
     * these at ~300px on mobile and ~200px in the desktop grid, so 600 covers
     * 2x retina. Smaller images are cropped but never upscaled.
     *
     * Public so `products:reencode-images --dry-run` can predict the result
     * against the same number the encoder actually applies.
     */
    public const EDGE = 600;

    private const QUALITY = 80;

    /**
     * Centre-crop to a square and re-encode to WebP.
     *
     * Called only by `products:reencode-images`, never from a request. It
     * renames the file and deletes the source, which cannot happen underneath
     * a Filament save without stranding the FileUpload field on a path that no
     * longer exists — see the note on ProductObserver.
     *
     * The crop costs nothing visually: the storefront card already centre-crops
     * with `object-cover`, so the visible result is identical. It stops storing
     * pixels that are never painted, and it means a 600px file gives the card
     * a full 600px of width instead of whatever a portrait photo had left over.
     *
     * Deliberately tolerant: a file GD cannot decode is left exactly as it is
     * and logged. Destroying an upload we failed to understand is worse than
     * serving it unconverted, and the warning is the signal that it happened.
     *
     * Idempotent: a second run over an already-normalised photo reads one file
     * header and returns, writing nothing and saving nothing.
     */
    public function execute(Product $product): void
    {
        $path = $product->image;

        // Kept separate from the exists() call below so a product with no photo
        // never reaches the disk at all.
        if (blank($path)) {
            return;
        }

        if (! Storage::disk('public')->exists($path)) {
            return;
        }

        if (! function_exists('imagewebp')) {
            Log::error('product.image.encode_unavailable', [
                'product_id' => $product->id,
                'path' => $path,
                'reason' => 'The GD extension is missing or was built without WebP support.',
            ]);

            return;
        }

        $contents = Storage::disk('public')->get($path);

        // What makes a second run of the command a no-op. Without it, every run
        // would re-encode every photo at quality 80 and compound generation
        // loss. Cheap: reads the header, not the pixels.
        if ($this->alreadyNormalised($path, $contents)) {
            return;
        }

        $binary = $this->encodeBinary($contents);

        if ($binary === null) {
            Log::warning('product.image.undecodable', [
                'product_id' => $product->id,
                'path' => $path,
            ]);

            return;
        }

        $target = $this->webpPath($path);

        // Write the new file, repoint the column, and only then drop the
        // source — and abandon the whole sequence the moment a step reports
        // failure. Deleting the source after a failed write would leave the
        // product pointing at nothing; deleting it after a failed save would
        // leave the row pointing at the file we just removed. An orphan is the
        // one outcome here that costs nothing but disk.
        if (! Storage::disk('public')->put($target, $binary)) {
            Log::error('product.image.write_failed', [
                'product_id' => $product->id,
                'path' => $path,
                'target' => $target,
            ]);

            return;
        }

        if ($target === $path) {
            return;
        }

        $product->image = $target;

        if (! $product->saveQuietly()) {
            Log::error('product.image.repoint_failed', [
                'product_id' => $product->id,
                'path' => $path,
                'target' => $target,
            ]);

            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * The encoder proper, with no model and no disk anywhere near it: raw image
     * bytes in, 600x600 WebP bytes out. Shared by `execute()` above and by the
     * upload path in StoreProductImage, so a photo coming in through the admin
     * and one converted later by the command go through identical pixels.
     *
     * Null means "GD could not turn these bytes into an image" — missing WebP
     * support or a file it cannot decode. Callers decide what to do about it;
     * neither of them destroys the source on the strength of a null.
     */
    public function encodeBinary(string $contents): ?string
    {
        if (! function_exists('imagewebp')) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        return $this->toWebp($this->squareOff($image));
    }

    private function alreadyNormalised(string $path, string $contents): bool
    {
        if (! str_ends_with(strtolower($path), '.webp')) {
            return false;
        }

        $size = @getimagesizefromstring($contents);

        return $size !== false
            && $size[0] === $size[1]
            && $size[0] <= self::EDGE;
    }

    /**
     * Centre-crop to the largest square the photo contains, then scale that to
     * 600. A source whose square is already smaller than 600 is cropped but not
     * upscaled — inventing pixels would only cost bytes.
     *
     * One `imagecopyresampled` does both: the source rectangle is the centred
     * square, the destination is the full target.
     *
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function squareOff($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $square = min($width, $height);
        $edge = min($square, self::EDGE);

        if ($width === $height && $width === $edge) {
            return $image;
        }

        $resized = imagecreatetruecolor($edge, $edge);

        // WebP carries alpha, so a transparent PNG stays transparent instead of
        // picking up the black that imagecreatetruecolor starts out with.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled(
            $resized,
            $image,
            0, 0,
            intdiv($width - $square, 2),
            intdiv($height - $square, 2),
            $edge, $edge,
            $square, $square,
        );
        imagedestroy($image);

        return $resized;
    }

    /**
     * @param  \GdImage  $image
     */
    private function toWebp($image): string
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        imagewebp($image, null, self::QUALITY);
        $binary = (string) ob_get_clean();

        imagedestroy($image);

        return $binary;
    }

    /**
     * Pure: derives the target path, touches nothing. Public so the dry run can
     * name the file a real run would write without reimplementing the rule.
     */
    public function webpPath(string $path): string
    {
        $directory = trim(dirname($path), '.');
        $name = pathinfo($path, PATHINFO_FILENAME);

        return ($directory === '' ? '' : $directory.'/').$name.'.webp';
    }
}
