<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The upload has to survive the Filament save untouched.
 *
 * Nothing may rename the file or delete it while the FileUpload field still
 * holds the path Filament wrote, because the field's own hydration silently
 * drops any path that is no longer on the disk — and once it is empty, the
 * next save writes `image = null` and the observer deletes the real photo.
 * Every assertion here that a stored path still exists is guarding that.
 */
class ProductImageUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_jpeg_uploaded_in_the_admin_is_converted_to_webp_before_it_is_recorded(): void
    {
        Storage::fake('public');

        $product = $this->createThroughFilament(
            UploadedFile::fake()->createWithContent('espresso.jpg', $this->jpeg()),
        );

        $this->assertStringStartsWith('products/', $product->image);
        $this->assertStringEndsWith('.webp', $product->image);
        Storage::disk('public')->assertExists($product->image);
        $this->assertSame([600, 600], $this->dimensions($product->image));
    }

    public function test_a_png_is_converted_too(): void
    {
        Storage::fake('public');

        $product = $this->createThroughFilament(
            UploadedFile::fake()->createWithContent('espresso.png', $this->png()),
        );

        $this->assertStringEndsWith('.webp', $product->image);
        Storage::disk('public')->assertExists($product->image);
        $this->assertSame([600, 600], $this->dimensions($product->image));
    }

    public function test_a_webp_upload_is_squared_off_as_well(): void
    {
        Storage::fake('public');

        $product = $this->createThroughFilament(
            UploadedFile::fake()->createWithContent('espresso.webp', $this->webp()),
        );

        $this->assertStringEndsWith('.webp', $product->image);
        Storage::disk('public')->assertExists($product->image);
        $this->assertSame([600, 600], $this->dimensions($product->image));
    }

    /**
     * The fallback. Returning null from `saveUploadedFileUsing` would make
     * Filament drop the entry *and* skip the `$file->delete()` that reaps the
     * Livewire temp file, so a photo GD cannot read is stored as it arrived.
     */
    public function test_a_file_gd_cannot_decode_is_still_stored_rather_than_dropped(): void
    {
        Storage::fake('public');

        $product = $this->createThroughFilament(
            UploadedFile::fake()->createWithContent('broken.jpg', 'this is not an image'),
        );

        $this->assertNotNull($product->image);
        $this->assertStringEndsWith('.jpg', $product->image);
        Storage::disk('public')->assertExists($product->image);
        $this->assertSame('this is not an image', Storage::disk('public')->get($product->image));
    }

    /**
     * Taking over storage must not take over the cleanup: Filament deletes the
     * temp file after the callback returns a path, and `storage/app/private/
     * livewire-tmp` grows forever if that stops happening.
     */
    public function test_the_livewire_temp_file_is_reaped_after_a_custom_store(): void
    {
        Storage::fake('public');

        $this->createThroughFilament(
            UploadedFile::fake()->createWithContent('espresso.jpg', $this->jpeg()),
        );

        $this->assertSame([], FileUploadConfiguration::storage()->files(FileUploadConfiguration::path()));
    }

    /**
     * Storage runs inside `beforeStateDehydrated`, which Filament reaches only
     * after `validate()` has passed. A submit that fails on another field must
     * therefore leave nothing behind on the permanent disk.
     */
    public function test_a_submit_that_fails_validation_writes_no_file(): void
    {
        Storage::fake('public');

        $page = Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreateProduct::class)
            ->fillForm([
                'category_id' => $this->category()->id,
                'name' => 'Espresso',
                'sort_order' => 0,
            ]);

        $page->set('data.image', [
            UploadedFile::fake()->createWithContent('espresso.jpg', $this->jpeg()),
        ]);

        $page->call('create')->assertHasFormErrors(['base_price']);

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, Product::count());
    }

    /**
     * The column and the disk have to agree. The old failure was a row saying
     * `.jpg` while only a `.webp` existed, which the storefront rendered as a
     * broken image.
     */
    public function test_the_stored_path_is_the_only_file_on_the_disk(): void
    {
        Storage::fake('public');

        $product = $this->createThroughFilament(
            UploadedFile::fake()->createWithContent('espresso.jpg', $this->jpeg()),
        );

        $this->assertSame([$product->image], Storage::disk('public')->allFiles());
    }

    public function test_the_storefront_url_points_at_a_file_that_exists(): void
    {
        Storage::fake('public');

        $product = $this->createThroughFilament(
            UploadedFile::fake()->createWithContent('espresso.jpg', $this->jpeg()),
        );

        $this->assertSame(Storage::disk('public')->url($product->image), $product->image_url);
        Storage::disk('public')->assertExists($product->image);
    }

    /**
     * A field hydrated with a path is a field FilePond can fetch a preview
     * for. An empty one after an upload is the "Waiting for size" symptom.
     */
    public function test_the_edit_page_loads_the_uploaded_photo_into_the_field(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/existing.jpg', $this->jpeg());

        $product = $this->product('products/existing.jpg');

        $state = $this->editPage($product)->get('data.image');

        $this->assertSame(['products/existing.jpg'], array_values($state));
        Storage::disk('public')->assertExists('products/existing.jpg');
    }

    public function test_an_existing_webp_uses_a_same_origin_filepond_preview_url(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/existing.webp', $this->webp());

        $page = $this->editPage($this->product('products/existing.webp'));
        $field = $page->instance()->form->getComponent(
            fn ($component): bool => $component instanceof FileUpload && $component->getName() === 'image',
        );

        $this->assertInstanceOf(FileUpload::class, $field);

        $preview = array_values($field->getUploadedFiles())[0];

        $this->assertSame('/storage/products/existing.webp', $preview['url']);
        $this->assertSame('image/webp', $preview['type']);
        $this->assertGreaterThan(0, $preview['size']);
    }

    /**
     * The exact regression. Hitting Save on the edit page without touching the
     * photo used to re-encode it underneath the form: the row moved to .webp,
     * the field kept pointing at the deleted .jpg, and the photo was one more
     * save away from being deleted outright.
     */
    public function test_saving_an_unrelated_field_leaves_the_photo_and_the_field_alone(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/existing.jpg', $this->jpeg());

        $product = $this->product('products/existing.jpg');
        $page = $this->editPage($product);

        $page->fillForm(['name' => 'Espresso Doppio'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('products/existing.jpg', $product->fresh()->image);
        $this->assertSame(['products/existing.jpg'], array_values($page->get('data.image')));
        Storage::disk('public')->assertExists('products/existing.jpg');
    }

    /**
     * Two saves in a row is what an owner does after the first one looks wrong.
     * It used to be what destroyed the photo.
     */
    public function test_saving_twice_in_a_row_does_not_lose_the_photo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/existing.jpg', $this->jpeg());

        $product = $this->product('products/existing.jpg');
        $page = $this->editPage($product);

        $page->call('save')->assertHasNoFormErrors();
        $page->call('save')->assertHasNoFormErrors();

        $this->assertSame('products/existing.jpg', $product->fresh()->image);
        Storage::disk('public')->assertExists('products/existing.jpg');
    }

    public function test_replacing_the_photo_stores_the_new_file_and_drops_only_the_old_one(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/existing.jpg', $this->jpeg());

        $product = $this->product('products/existing.jpg');

        $page = $this->editPage($product);
        $page->set('data.image', [
            UploadedFile::fake()->createWithContent('replacement.png', $this->png()),
        ]);
        $page->call('save')->assertHasNoFormErrors();

        $stored = $product->fresh()->image;

        $this->assertNotSame('products/existing.jpg', $stored);
        $this->assertStringEndsWith('.webp', $stored);

        Storage::disk('public')->assertExists($stored);
        Storage::disk('public')->assertMissing('products/existing.jpg');

        // No orphan left behind, and the field points at the file that is there.
        $this->assertSame([$stored], Storage::disk('public')->allFiles());
        $this->assertSame([$stored], array_values($page->get('data.image')));
    }

    public function test_clearing_the_photo_removes_the_file_and_leaves_the_product(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/existing.jpg', $this->jpeg());

        $product = $this->product('products/existing.jpg');

        $this->editPage($product)
            ->fillForm(['image' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($product->fresh()->image);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    private function createThroughFilament(UploadedFile $file): Product
    {
        $category = $this->category();

        $page = Livewire::actingAs(User::factory()->admin()->create())
            ->test(CreateProduct::class)
            ->fillForm([
                'category_id' => $category->id,
                'name' => 'Espresso',
                'base_price' => '2.20',
                'sort_order' => 0,
            ]);

        $page->set('data.image', [$file])
            ->call('create')
            ->assertHasNoFormErrors();

        return Product::query()->latest('id')->firstOrFail();
    }

    private function editPage(Product $product)
    {
        return Livewire::actingAs(User::factory()->admin()->create())
            ->test(EditProduct::class, ['record' => $product->getRouteKey()]);
    }

    private function category(): Category
    {
        return Category::firstOrCreate(
            ['slug' => 'kafedes'],
            ['name' => 'Καφέδες', 'sort_order' => 0, 'is_active' => true],
        );
    }

    private function product(?string $image): Product
    {
        return Product::create([
            'category_id' => $this->category()->id,
            'name' => 'Espresso',
            'image' => $image,
            'base_price' => '2.20',
            'is_available' => true,
            'sort_order' => 0,
        ]);
    }

    private function jpeg(): string
    {
        return $this->render('imagejpeg');
    }

    private function png(): string
    {
        return $this->render('imagepng');
    }

    private function webp(): string
    {
        return $this->render('imagewebp');
    }

    private function dimensions(string $path): array
    {
        $image = imagecreatefromstring(Storage::disk('public')->get($path));

        return [imagesx($image), imagesy($image)];
    }

    private function render(string $encoder): string
    {
        $image = imagecreatetruecolor(1200, 900);
        imagefilledrectangle($image, 0, 0, 1200, 900, imagecolorallocate($image, 214, 179, 140));
        imageline($image, 0, 0, 1200, 900, imagecolorallocate($image, 40, 30, 25));

        ob_start();
        $encoder($image);
        $binary = (string) ob_get_clean();

        imagedestroy($image);

        return $binary;
    }
}
