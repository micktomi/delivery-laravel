<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageEncodingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagewebp')) {
            $this->fail('The GD extension with WebP support is required: stored photos are re-encoded on save.');
        }
    }

    public function test_a_large_png_is_stored_as_a_600px_webp_and_the_original_is_removed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/big.png', $this->png(1600, 1600));

        $product = $this->product('products/big.png');

        $this->assertSame('products/big.webp', $product->fresh()->image);
        Storage::disk('public')->assertExists('products/big.webp');
        Storage::disk('public')->assertMissing('products/big.png');

        [$width, $height] = $this->dimensions('products/big.webp');
        $this->assertSame(600, $width);
        $this->assertSame(600, $height);
    }

    /**
     * The invariant that replaced "longest edge is 600". Preserving the aspect
     * ratio was never useful: the card centre-crops with object-cover anyway,
     * so a 600x300 file just stores 300px of height nobody ever sees and hands
     * the card 600px of width it cannot use at 2x.
     */
    public function test_a_wide_photo_is_cropped_to_a_square_rather_than_letterboxed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/wide.png', $this->png(2000, 1000));

        $this->product('products/wide.png');

        [$width, $height] = $this->dimensions('products/wide.webp');
        $this->assertSame(600, $width);
        $this->assertSame(600, $height);
    }

    public function test_a_tall_photo_is_cropped_to_a_square_too(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/tall.png', $this->png(800, 1600));

        $this->product('products/tall.png');

        $this->assertSame([600, 600], $this->dimensions('products/tall.webp'));
    }

    /**
     * The crop is centred, not taken from the top-left. A 1200x600 source with
     * a distinctly coloured middle third proves which square was kept.
     */
    public function test_the_crop_is_taken_from_the_centre(): void
    {
        Storage::fake('public');

        $image = imagecreatetruecolor(1200, 600);
        imagefilledrectangle($image, 0, 0, 1199, 599, imagecolorallocate($image, 200, 30, 30));
        imagefilledrectangle($image, 300, 0, 899, 599, imagecolorallocate($image, 30, 200, 30));
        Storage::disk('public')->put('products/banded.png', $this->render($image, 'imagepng'));

        $this->product('products/banded.png');

        $encoded = imagecreatefromstring(Storage::disk('public')->get('products/banded.webp'));
        $centre = imagecolorsforindex($encoded, imagecolorat($encoded, 300, 300));

        $this->assertGreaterThan($centre['red'], $centre['green'], 'The centre band was not the square that survived.');
    }

    public function test_an_image_smaller_than_the_target_is_cropped_but_not_upscaled(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/small.png', $this->png(240, 180));

        $this->product('products/small.png');

        // 180 is the largest square this photo contains; padding it out to 600
        // would only cost bytes for pixels that were never captured.
        $this->assertSame([180, 180], $this->dimensions('products/small.webp'));
    }

    /**
     * The invariant the whole action exists for: whatever FilePond did or did
     * not do in the browser, the column cannot end up pointing at a JPEG.
     */
    public function test_a_jpeg_upload_still_ends_up_as_webp_in_the_column(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/photo.jpg', $this->jpeg(1200, 1200));

        $product = $this->product('products/photo.jpg');

        $this->assertStringEndsWith('.webp', $product->fresh()->image);
        Storage::disk('public')->assertMissing('products/photo.jpg');
    }

    public function test_replacing_an_image_encodes_the_new_file_and_drops_both_old_ones(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/old.webp', $this->webp(600, 600));
        Storage::disk('public')->put('products/new.png', $this->png(1600, 1600));

        $product = $this->product('products/old.webp');

        $product->update(['image' => 'products/new.png']);

        $this->assertSame('products/new.webp', $product->fresh()->image);
        Storage::disk('public')->assertMissing('products/old.webp');
        Storage::disk('public')->assertMissing('products/new.png');
        Storage::disk('public')->assertExists('products/new.webp');
    }

    public function test_saving_an_unrelated_field_does_not_re_encode(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/stable.png', $this->png(800, 800));

        $product = $this->product('products/stable.png');
        $encoded = Storage::disk('public')->get('products/stable.webp');

        $product->update(['base_price' => '9.99']);

        $this->assertSame($encoded, Storage::disk('public')->get('products/stable.webp'));
    }

    /**
     * A file GD cannot read is left untouched rather than destroyed. This also
     * pins the behaviour the existing cleanup tests rely on.
     */
    public function test_an_undecodable_file_is_left_alone(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/not-an-image.jpg', 'x');

        $product = $this->product('products/not-an-image.jpg');

        $this->assertSame('products/not-an-image.jpg', $product->fresh()->image);
        Storage::disk('public')->assertExists('products/not-an-image.jpg');
    }

    /**
     * The trigger cannot tell a first save from a later one — `wasChanged`
     * is already false on create and `wasRecentlyCreated` never resets — so
     * the action itself has to be safe to call repeatedly. Re-encoding a WebP
     * at quality 80 on every save would compound generation loss.
     */
    public function test_encoding_the_same_product_repeatedly_leaves_the_file_byte_identical(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/repeat.png', $this->png(1600, 1600));

        $product = $this->product('products/repeat.png');
        $encoded = Storage::disk('public')->get('products/repeat.webp');

        app(\App\Actions\EncodeProductImage::class)->execute($product->fresh());
        app(\App\Actions\EncodeProductImage::class)->execute($product->fresh());

        $this->assertSame($encoded, Storage::disk('public')->get('products/repeat.webp'));
    }

    /**
     * The one case the extension check alone would wave through: a WebP that
     * is genuinely too large still gets downscaled.
     */
    public function test_an_oversized_webp_is_still_downscaled(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/huge.webp', $this->webp(1400, 1400));

        $this->product('products/huge.webp');

        [$width, $height] = $this->dimensions('products/huge.webp');
        $this->assertSame(600, $width);
        $this->assertSame(600, $height);
    }

    /**
     * The case a guarded trigger strands entirely: a product still holding a
     * photo from before server-side encoding existed. Nothing is dirty, nothing
     * changed, `wasRecentlyCreated` is false — and it must still convert, because
     * hitting Save in the admin is the obvious thing an owner will try.
     */
    public function test_a_legacy_jpeg_converts_on_a_save_that_changes_nothing(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/legacy.jpg', $this->jpeg(1600, 1600));

        $product = $this->product('products/legacy.jpg');

        // Put the row back the way a pre-encoder upload would have left it,
        // without going through the observer.
        $product->forceFill(['image' => 'products/legacy.jpg'])->saveQuietly();
        Storage::disk('public')->put('products/legacy.jpg', $this->jpeg(1600, 1600));

        $reloaded = Product::find($product->id);
        $this->assertFalse($reloaded->wasRecentlyCreated);

        $reloaded->save();

        $this->assertSame('products/legacy.webp', $reloaded->fresh()->image);
        $this->assertSame([600, 600], $this->dimensions('products/legacy.webp'));
        Storage::disk('public')->assertMissing('products/legacy.jpg');
    }

    public function test_a_product_without_an_image_never_touches_the_disk(): void
    {
        Storage::fake('public');

        $product = $this->product(null);
        $product->save();
        $product->update(['base_price' => '3.30']);

        $this->assertNull($product->fresh()->image);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function dimensions(string $path): array
    {
        $image = imagecreatefromstring(Storage::disk('public')->get($path));

        return [imagesx($image), imagesy($image)];
    }

    private function png(int $width, int $height): string
    {
        return $this->render($this->canvas($width, $height), 'imagepng');
    }

    private function jpeg(int $width, int $height): string
    {
        return $this->render($this->canvas($width, $height), 'imagejpeg');
    }

    private function webp(int $width, int $height): string
    {
        return $this->render($this->canvas($width, $height), 'imagewebp');
    }

    /**
     * Flat colour compresses to almost nothing, which would make a size
     * assertion meaningless; the diagonal gives the encoder real work.
     *
     * @return \GdImage
     */
    private function canvas(int $width, int $height)
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 214, 179, 140));
        imageline($image, 0, 0, $width, $height, imagecolorallocate($image, 40, 30, 25));

        return $image;
    }

    private function render($image, callable $encoder): string
    {
        ob_start();
        $encoder($image);
        $binary = (string) ob_get_clean();

        imagedestroy($image);

        return $binary;
    }

    private function product(?string $image): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'kafedes'],
            ['name' => 'Καφέδες', 'sort_order' => 0, 'is_active' => true],
        );

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Espresso',
            'image' => $image,
            'base_price' => '2.20',
            'is_available' => true,
            'sort_order' => 0,
        ]);
    }
}
