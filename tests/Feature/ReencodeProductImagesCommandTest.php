<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
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

    public function test_dry_run_names_the_target_file_for_a_non_webp_photo(): void
    {
        Storage::fake('public');

        $this->legacyProduct('Espresso', 'products/one.jpg', 1600, 1200);

        // One expectation per output line: each registers its own mock
        // expectation on doWrite, and the first matcher to accept a line
        // consumes it, so two substrings from the same line never both clear.
        $this->artisan('products:reencode-images', ['--dry-run' => true])
            ->expectsOutputToContain('products/one.jpg -> products/one.webp  1600x1200 -> 600x600')
            ->expectsOutputToContain('1 to convert')
            ->assertSuccessful();
    }

    /**
     * The regression this whole rewrite exists for. A rectangular .webp is
     * rewritten by a real run (see the squares-off test above) but the old dry
     * run filtered on the extension alone and reported nothing at all.
     */
    public function test_dry_run_reports_a_rectangular_webp_that_a_real_run_would_rewrite(): void
    {
        Storage::fake('public');

        $this->legacyProduct('Cappuccino', 'products/old.webp', 450, 600, 'imagewebp');

        $this->artisan('products:reencode-images', ['--dry-run' => true])
            ->expectsOutputToContain('products/old.webp  450x600 -> 450x450')
            ->expectsOutputToContain('1 to re-square')
            ->assertSuccessful();
    }

    /**
     * A .webp square larger than the target edge is still re-encoded, and the
     * plan has to say so — the size guard is `<= EDGE`, not "is a square".
     */
    public function test_dry_run_reports_an_oversized_webp_square(): void
    {
        Storage::fake('public');

        $this->legacyProduct('Latte', 'products/big.webp', 1200, 1200, 'imagewebp');

        $this->artisan('products:reencode-images', ['--dry-run' => true])
            ->expectsOutputToContain('products/big.webp  1200x1200 -> 600x600')
            ->expectsOutputToContain('1 to re-square')
            ->assertSuccessful();
    }

    public function test_dry_run_reports_a_product_whose_file_is_gone(): void
    {
        Storage::fake('public');

        $this->productWithoutFile('Frappe', 'products/vanished.webp');

        $this->artisan('products:reencode-images', ['--dry-run' => true])
            ->expectsOutputToContain('Frappe  products/vanished.webp')
            ->expectsOutputToContain('1 missing')
            ->assertSuccessful();
    }

    public function test_dry_run_reports_a_file_gd_cannot_read(): void
    {
        Storage::fake('public');

        $this->productWithoutFile('Mocha', 'products/broken.webp');
        Storage::disk('public')->put('products/broken.webp', 'this is not an image');

        $this->artisan('products:reencode-images', ['--dry-run' => true])
            ->expectsOutputToContain('Mocha  products/broken.webp')
            ->expectsOutputToContain('1 unreadable')
            ->assertSuccessful();
    }

    public function test_dry_run_reports_an_already_correct_photo_as_needing_nothing(): void
    {
        Storage::fake('public');

        $this->legacyProduct('Freddo', 'products/done.webp', 600, 600, 'imagewebp');

        $this->artisan('products:reencode-images', ['--dry-run' => true])
            ->expectsOutputToContain('Freddo  products/done.webp  600x600')
            ->expectsOutputToContain('1 already correct')
            ->assertSuccessful();
    }

    public function test_dry_run_counts_every_outcome_in_one_pass(): void
    {
        Storage::fake('public');

        $this->legacyProduct('Espresso', 'products/one.jpg', 1600, 1600);
        $this->legacyProduct('Americano', 'products/two.png', 800, 800);
        $this->legacyProduct('Cappuccino', 'products/old.webp', 450, 600, 'imagewebp');
        $this->legacyProduct('Freddo', 'products/done.webp', 600, 600, 'imagewebp');
        $this->productWithoutFile('Frappe', 'products/vanished.webp');
        $this->productWithoutFile('Mocha', 'products/broken.webp');
        Storage::disk('public')->put('products/broken.webp', 'not an image');

        $this->artisan('products:reencode-images', ['--dry-run' => true])
            ->expectsOutputToContain(
                '6 checked — 2 to convert, 1 to re-square, 1 missing, 1 unreadable, 1 already correct.'
            )
            ->assertSuccessful();
    }

    /**
     * The guarantee the deployment runbook leans on: the plan is read-only.
     * Files, product rows and the order history all come out untouched, and
     * the timestamps prove no silent save happened.
     */
    public function test_dry_run_touches_no_file_no_product_and_no_order(): void
    {
        Storage::fake('public');

        $jpeg = $this->legacyProduct('Espresso', 'products/one.jpg', 1600, 1600);
        $webp = $this->legacyProduct('Cappuccino', 'products/old.webp', 450, 600, 'imagewebp');
        $this->productWithoutFile('Frappe', 'products/vanished.webp');

        $order = Order::factory()->create();
        $item = $order->items()->create([
            'product_id' => $jpeg->id,
            'product_name' => 'Espresso',
            'base_price' => '2.20',
            'quantity' => 1,
            'selected_options' => [],
            'line_total' => '2.20',
        ]);

        $before = [
            'jpeg' => Storage::disk('public')->get('products/one.jpg'),
            'webp' => Storage::disk('public')->get('products/old.webp'),
            'files' => Storage::disk('public')->allFiles(),
            'products' => Product::query()->orderBy('id')->get(['id', 'image', 'updated_at'])->toArray(),
            'orders' => Order::query()->count(),
            'items' => OrderItem::query()->orderBy('id')->get(['id', 'product_id', 'updated_at'])->toArray(),
        ];

        $this->travelTo(now()->addHour());

        $this->artisan('products:reencode-images', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before['jpeg'], Storage::disk('public')->get('products/one.jpg'));
        $this->assertSame($before['webp'], Storage::disk('public')->get('products/old.webp'));
        $this->assertSame($before['files'], Storage::disk('public')->allFiles());
        Storage::disk('public')->assertMissing('products/one.webp');
        Storage::disk('public')->assertMissing('products/vanished.webp');

        $this->assertSame(
            $before['products'],
            Product::query()->orderBy('id')->get(['id', 'image', 'updated_at'])->toArray(),
        );
        $this->assertSame($before['orders'], Order::query()->count());
        $this->assertSame(
            $before['items'],
            OrderItem::query()->orderBy('id')->get(['id', 'product_id', 'updated_at'])->toArray(),
        );

        $this->assertSame($jpeg->id, $item->fresh()->product_id);
        $this->assertSame('products/old.webp', $webp->fresh()->image);
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

    /**
     * A row pointing at a path with nothing behind it — what a manual file
     * tidy-up on the server leaves, and the case the plan has to call out
     * rather than promise a conversion that cannot happen.
     */
    private function productWithoutFile(string $name, string $image): Product
    {
        $category = Category::firstOrCreate(
            ['slug' => 'kafedes'],
            ['name' => 'Καφέδες', 'sort_order' => 0, 'is_active' => true],
        );

        return Product::withoutEvents(fn () => Product::create([
            'category_id' => $category->id,
            'name' => $name,
            'image' => $image,
            'base_price' => '2.20',
            'is_available' => true,
            'sort_order' => 0,
        ]));
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
