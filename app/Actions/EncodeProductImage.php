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
     * Centre-crop to a square and re-encode to WebP on the server, so what
     * lands on disk is the same whatever the browser did on the way in. The
     * FilePond transform still runs — it saves the café's phone from uploading
     * 8 MB — but it is no longer the thing we trust for what gets stored.
     *
     * The crop costs nothing visually: the storefront card already centre-crops
     * with `object-cover`, so the visible result is identical. It stops storing
     * pixels that are never painted, and it means a 600px file gives the card
     * a full 600px of width instead of whatever a portrait photo had left over.
     *
     * Deliberately tolerant: a file GD cannot decode is left exactly as it is
     * and logged. Destroying an upload we failed to understand is worse than
     * serving it unconverted, and the warning is the signal that it happened.
     */
    public function execute(Product $product): void
    {
        $path = $product->image;

        // Checked before anything else and kept separate from the exists()
        // call below: the observer now calls this on every single product save,
        // and a product with no photo must not reach the disk at all.
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

        // Idempotency guard, and it has to live here rather than in the trigger:
        // `wasChanged('image')` is already false by the time `saved` fires on a
        // create, and `wasRecentlyCreated` stays true for the life of the model
        // instance. Any trigger precise enough to catch the first case re-fires
        // on the second, and re-encoding a WebP at quality 80 on every save
        // compounds generation loss. Cheap: reads the header, not the pixels.
        if ($this->alreadyNormalised($path, $contents)) {
            return;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            Log::warning('product.image.undecodable', [
                'product_id' => $product->id,
                'path' => $path,
            ]);

            return;
        }

        $image = $this->squareOff($image);
        $binary = $this->toWebp($image);

        $target = $this->webpPath($path);

        Storage::disk('public')->put($target, $binary);

        // Write the new file, repoint the column, and only then drop the
        // source. Losing the delete leaves an orphan; losing the write in the
        // other order would leave a product pointing at nothing.
        if ($target !== $path) {
            $product->image = $target;
            $product->saveQuietly();

            Storage::disk('public')->delete($path);
        }
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
