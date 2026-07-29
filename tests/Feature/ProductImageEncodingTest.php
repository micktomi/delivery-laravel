<?php

namespace Tests\Feature;

use App\Actions\EncodeProductImage;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The encoder is an explicit, opt-in step now — `products:reencode-images` is
 * its only caller. It is never wired to a model event, because renaming and
 * deleting a file mid-save is what broke the Filament upload field; the first
 * test here is what stops that from being reintroduced.
 */
class ProductImageEncodingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagewebp')) {
            $this->fail('The GD extension with WebP support is required by products:reencode-images.');
        }
    }

    /**
     * The invariant the whole fix rests on: saving a product touches no file
     * and renames nothing, so whatever Filament wrote is still there when the
     * FileUpload field hydrates from the column again.
     */
    public function test_saving_a_product_never_converts_or_renames_the_photo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/photo.jpg', $this->jpeg(1600, 1600));

        $product = $this->product('products/photo.jpg');
        $original = Storage::disk('public')->get('products/photo.jpg');

        $product->update(['base_price' => '9.99']);
        $product->save();

        $this->assertSame('products/photo.jpg', $product->fresh()->image);
        $this->assertSame(['products/photo.jpg'], Storage::disk('public')->allFiles());
        $this->assertSame($original, Storage::disk('public')->get('products/photo.jpg'));
    }

    public function test_a_large_png_is_encoded_to_a_600px_webp_and_the_original_is_removed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/big.png', $this->png(1600, 1600));

        $product = $this->encode($this->product('products/big.png'));

        $this->assertSame('products/big.webp', $product->fresh()->image);
        Storage::disk('public')->assertExists('products/big.webp');
        Storage::disk('public')->assertMissing('products/big.png');

        $this->assertSame([600, 600], $this->dimensions('products/big.webp'));
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

        $this->encode($this->product('products/wide.png'));

        $this->assertSame([600, 600], $this->dimensions('products/wide.webp'));
    }

    public function test_a_tall_photo_is_cropped_to_a_square_too(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/tall.png', $this->png(800, 1600));

        $this->encode($this->product('products/tall.png'));

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

        $this->encode($this->product('products/banded.png'));

        $encoded = imagecreatefromstring(Storage::disk('public')->get('products/banded.webp'));
        $centre = imagecolorsforindex($encoded, imagecolorat($encoded, 300, 300));

        $this->assertGreaterThan($centre['red'], $centre['green'], 'The centre band was not the square that survived.');
    }

    public function test_an_image_smaller_than_the_target_is_cropped_but_not_upscaled(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/small.png', $this->png(240, 180));

        $this->encode($this->product('products/small.png'));

        // 180 is the largest square this photo contains; padding it out to 600
        // would only cost bytes for pixels that were never captured.
        $this->assertSame([180, 180], $this->dimensions('products/small.webp'));
    }

    public function test_a_jpeg_ends_up_as_webp_in_the_column(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/photo.jpg', $this->jpeg(1200, 1200));

        $product = $this->encode($this->product('products/photo.jpg'));

        $this->assertSame('products/photo.webp', $product->fresh()->image);
        Storage::disk('public')->assertMissing('products/photo.jpg');
    }

    /**
     * Write, repoint, then delete — never the other way round. At no point may
     * the column name a file that is not on the disk.
     */
    public function test_the_column_and_the_disk_agree_after_encoding(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/photo.jpg', $this->jpeg(1200, 1200));

        $product = $this->encode($this->product('products/photo.jpg'));

        $stored = $product->fresh()->image;

        Storage::disk('public')->assertExists($stored);
        $this->assertSame([$stored], Storage::disk('public')->allFiles());
    }

    /**
     * A file GD cannot read is left untouched rather than destroyed, and the
     * column keeps pointing at it, so the storefront carries on serving it.
     */
    public function test_an_undecodable_file_is_left_alone(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/not-an-image.jpg', 'x');

        $product = $this->encode($this->product('products/not-an-image.jpg'));

        $this->assertSame('products/not-an-image.jpg', $product->fresh()->image);
        Storage::disk('public')->assertExists('products/not-an-image.jpg');
    }

    /**
     * Re-encoding a WebP at quality 80 on every run would compound generation
     * loss, so a second pass has to be a genuine no-op.
     */
    public function test_encoding_the_same_product_repeatedly_leaves_the_file_byte_identical(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/repeat.png', $this->png(1600, 1600));

        $product = $this->encode($this->product('products/repeat.png'));
        $encoded = Storage::disk('public')->get('products/repeat.webp');

        $this->encode($product->fresh());
        $this->encode($product->fresh());

        $this->assertSame($encoded, Storage::disk('public')->get('products/repeat.webp'));
        $this->assertSame('products/repeat.webp', $product->fresh()->image);
    }

    /**
     * The one case the extension check alone would wave through: a WebP that
     * is genuinely too large still gets downscaled, in place.
     */
    public function test_an_oversized_webp_is_still_downscaled(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/huge.webp', $this->webp(1400, 1400));

        $product = $this->encode($this->product('products/huge.webp'));

        $this->assertSame([600, 600], $this->dimensions('products/huge.webp'));

        // Rewritten under its own name, so nothing repoints and nothing is
        // deleted — the file the column already names must survive.
        $this->assertSame('products/huge.webp', $product->fresh()->image);
        Storage::disk('public')->assertExists('products/huge.webp');
    }

    public function test_a_product_without_an_image_never_touches_the_disk(): void
    {
        Storage::fake('public');

        $product = $this->product(null);
        $this->encode($product);

        $this->assertNull($product->fresh()->image);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_column_naming_a_file_that_is_gone_is_left_as_it_is(): void
    {
        Storage::fake('public');

        $product = $this->encode($this->product('products/vanished.jpg'));

        $this->assertSame('products/vanished.jpg', $product->fresh()->image);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function encode(Product $product): Product
    {
        app(EncodeProductImage::class)->execute($product);

        return $product;
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
