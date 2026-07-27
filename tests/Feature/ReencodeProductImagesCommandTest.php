<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReencodeProductImagesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_converts_a_backlog_of_legacy_photos(): void
    {
        Storage::fake('public');

        $espresso = $this->legacyProduct('Espresso', 'products/one.jpg', 1600, 1600);
        $freddo = $this->legacyProduct('Freddo', 'products/two.png', 2000, 1000);

        $this->artisan('products:reencode-images')
            ->assertSuccessful();

        $this->assertSame('products/one.webp', $espresso->fresh()->image);
        $this->assertSame('products/two.webp', $freddo->fresh()->image);

        Storage::disk('public')->assertMissing('products/one.jpg');
        Storage::disk('public')->assertMissing('products/two.png');
    }

    /**
     * A rectangular .webp written by the first version of the encoder still
     * needs the square crop, so the command cannot filter on extension alone.
     */
    public function test_it_squares_off_a_rectangular_webp_left_by_an_earlier_encoder(): void
    {
        Storage::fake('public');

        $product = $this->legacyProduct('Cappuccino', 'products/old.webp', 450, 600, 'imagewebp');

        $this->artisan('products:reencode-images')->assertSuccessful();

        $this->assertSame('products/old.webp', $product->fresh()->image);

        $encoded = imagecreatefromstring(Storage::disk('public')->get('products/old.webp'));
        $this->assertSame([450, 450], [imagesx($encoded), imagesy($encoded)]);
    }

    public function test_dry_run_reports_without_changing_anything(): void
    {
        Storage::fake('public');

        $product = $this->legacyProduct('Espresso', 'products/one.jpg', 1600, 1600);

        $this->artisan('products:reencode-images', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame('products/one.jpg', $product->fresh()->image);
        Storage::disk('public')->assertExists('products/one.jpg');
        Storage::disk('public')->assertMissing('products/one.webp');
    }

    public function test_an_already_converted_photo_is_left_byte_identical(): void
    {
        Storage::fake('public');

        $this->legacyProduct('Espresso', 'products/done.webp', 600, 600, 'imagewebp');
        $before = Storage::disk('public')->get('products/done.webp');

        $this->artisan('products:reencode-images')->assertSuccessful();

        $this->assertSame($before, Storage::disk('public')->get('products/done.webp'));
    }

    public function test_it_survives_a_menu_with_no_photos(): void
    {
        Storage::fake('public');

        $this->legacyProduct('Espresso', null, 0, 0);

        $this->artisan('products:reencode-images')->assertSuccessful();
    }

    /**
     * Writes the file and points the row at it without the observer running, so
     * the fixture is genuinely what a pre-encoder upload left behind.
     */
    private function legacyProduct(string $name, ?string $image, int $width, int $height, string $encoder = 'imagejpeg'): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'kafedes'],
            ['name' => 'Καφέδες', 'sort_order' => 0, 'is_active' => true],
        );

        $product = Product::withoutEvents(fn () => Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'image' => $image,
            'base_price' => '2.20',
            'is_available' => true,
            'sort_order' => 0,
        ]));

        if ($image !== null) {
            $encoder = str_ends_with($image, '.png') ? 'imagepng' : $encoder;

            Storage::disk('public')->put($image, $this->render($width, $height, $encoder));
        }

        return $product;
    }

    private function render(int $width, int $height, string $encoder): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 214, 179, 140));
        imageline($image, 0, 0, $width, $height, imagecolorallocate($image, 40, 30, 25));

        ob_start();
        $encoder($image);
        $binary = (string) ob_get_clean();

        imagedestroy($image);

        return $binary;
    }
}
