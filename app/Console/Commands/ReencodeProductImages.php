<?php

namespace App\Console\Commands;

use App\Actions\EncodeProductImage;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * One-off migration path for photos uploaded before server-side encoding
 * existed. Saving a product in the admin converts it too, but nobody is going
 * to open and re-save every product by hand.
 */
class ReencodeProductImages extends Command
{
    protected $signature = 'products:reencode-images {--dry-run : List what would change without touching anything}';

    protected $description = 'Re-encode existing product photos to 600x600 WebP';

    public function handle(EncodeProductImage $encoder): int
    {
        if (! function_exists('imagewebp')) {
            $this->error('GD with WebP support is missing. Install php8.3-gd and try again.');

            return self::FAILURE;
        }

        // Deliberately not filtered to non-.webp paths: a photo stored as a
        // rectangular .webp by an earlier version of the encoder also needs
        // the square crop, and the action decides that per file.
        $products = Product::query()
            ->whereNotNull('image')
            ->orderBy('id')
            ->get();

        if ($products->isEmpty()) {
            $this->info('No product photos to check.');

            return self::SUCCESS;
        }

        $converted = 0;

        foreach ($products as $product) {
            $before = $product->image;

            if ($this->option('dry-run')) {
                if (! str_ends_with(strtolower($before), '.webp')) {
                    $this->line("would convert  {$product->name}  {$before}");
                    $converted++;
                }

                continue;
            }

            $encoder->execute($product);

            $after = $product->fresh()->image;

            if ($after !== $before) {
                $this->line("converted  {$product->name}  {$before} -> {$after}");
                $converted++;
            }
        }

        $verb = $this->option('dry-run') ? 'would be converted' : 'converted';
        $this->info("{$products->count()} checked, {$converted} {$verb}.");

        return self::SUCCESS;
    }
}
