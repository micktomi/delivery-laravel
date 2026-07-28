<?php

namespace App\Console\Commands;

use App\Actions\EncodeProductImage;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * One-off migration path for photos uploaded before server-side encoding
 * existed. Saving a product in the admin converts it too, but nobody is going
 * to open and re-save every product by hand.
 */
class ReencodeProductImages extends Command
{
    protected $signature = 'products:reencode-images {--dry-run : List what would change without touching anything}';

    protected $description = 'Re-encode existing product photos to 600x600 WebP';

    /** Stored as something other than WebP: converted, and the column repointed. */
    private const CONVERT = 'convert';

    /** Already WebP but not a <=600px square: rewritten in place, same path. */
    private const RESQUARE = 're-square';

    /** The column names a file that is not on the disk. */
    private const MISSING = 'missing';

    /** On the disk, but GD cannot read the header. Left untouched, logged. */
    private const UNREADABLE = 'unreadable';

    /** Already a <=600px WebP square. Skipped by the encoder's own guard. */
    private const OK = 'ok';

    public function handle(EncodeProductImage $encoder): int
    {
        if (! function_exists('imagewebp')) {
            $this->error('GD with WebP support is missing. Install php8.3-gd and try again.');

            return self::FAILURE;
        }

        // Deliberately not filtered to non-.webp paths: a photo stored as a
        // rectangular .webp by an earlier version of the encoder also needs
        // the square crop, and the action decides that per file. Empty strings
        // are excluded so both paths agree on what "checked" counts.
        $products = Product::query()
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->orderBy('id')
            ->get();

        if ($products->isEmpty()) {
            $this->info('No product photos to check.');

            return self::SUCCESS;
        }

        return $this->option('dry-run')
            ? $this->report($products, $encoder)
            : $this->convert($products, $encoder);
    }

    /**
     * Read-only. Opens each file to read its header and writes nothing: no
     * disk write, no delete, no save, no query beyond the select above.
     *
     * The previous version counted only non-.webp paths, so a rectangular
     * .webp left by an earlier encoder was absent from the plan and then
     * rewritten by the real run — the one thing a dry run must never do.
     *
     * @param  Collection<int, Product>  $products
     */
    private function report(Collection $products, EncodeProductImage $encoder): int
    {
        $counts = array_fill_keys(
            [self::CONVERT, self::RESQUARE, self::MISSING, self::UNREADABLE, self::OK],
            0,
        );

        foreach ($products as $product) {
            $plan = $this->inspect($product, $encoder);
            $counts[$plan['outcome']]++;

            $this->line(sprintf('%-11s %s  %s', $plan['outcome'], $product->name, $plan['detail']));
        }

        $this->newLine();
        $this->info(sprintf(
            '%d checked — %d to convert, %d to re-square, %d missing, %d unreadable, %d already correct.',
            $products->count(),
            $counts[self::CONVERT],
            $counts[self::RESQUARE],
            $counts[self::MISSING],
            $counts[self::UNREADABLE],
            $counts[self::OK],
        ));

        if ($counts[self::MISSING] > 0) {
            $this->warn('Missing files are skipped by a real run; the product keeps pointing at nothing.');
        }

        if ($counts[self::UNREADABLE] > 0) {
            $this->warn('Unreadable files are left as they are and logged as product.image.undecodable.');
        }

        $this->comment('Dry run — no file was written or deleted, and no record was saved.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function convert(Collection $products, EncodeProductImage $encoder): int
    {
        $converted = 0;

        foreach ($products as $product) {
            $before = $product->image;

            $encoder->execute($product);

            $after = $product->fresh()->image;

            if ($after !== $before) {
                $this->line("converted  {$product->name}  {$before} -> {$after}");
                $converted++;
            }
        }

        $this->info("{$products->count()} checked, {$converted} converted.");

        return self::SUCCESS;
    }

    /**
     * Mirrors the early-return ladder in EncodeProductImage::execute(), in the
     * same order, so the plan matches what a real run does.
     *
     * Reads the header only — never `imagecreatefromstring`. Decoding every
     * photo to answer a question about its dimensions would let a dry run
     * exhaust memory on a catalogue a real run walks one file at a time, and a
     * dry run that can fall over is worse than no dry run at all.
     *
     * @return array{outcome: string, detail: string}
     */
    private function inspect(Product $product, EncodeProductImage $encoder): array
    {
        $path = $product->image;
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return ['outcome' => self::MISSING, 'detail' => $path];
        }

        $size = @getimagesizefromstring((string) $disk->get($path));

        if ($size === false) {
            return ['outcome' => self::UNREADABLE, 'detail' => $path];
        }

        [$width, $height] = $size;
        $isWebp = str_ends_with(strtolower($path), '.webp');

        // The encoder's own idempotency guard, restated: a WebP square no
        // larger than the target edge is already what we want.
        if ($isWebp && $width === $height && $width <= EncodeProductImage::EDGE) {
            return ['outcome' => self::OK, 'detail' => "{$path}  {$width}x{$height}"];
        }

        // squareOff() centre-crops to the shorter side, then scales that down
        // to EDGE. It never upscales, so a small photo keeps its own size.
        $edge = min($width, $height, EncodeProductImage::EDGE);

        return $isWebp
            ? [
                'outcome' => self::RESQUARE,
                'detail' => "{$path}  {$width}x{$height} -> {$edge}x{$edge}",
            ]
            : [
                'outcome' => self::CONVERT,
                'detail' => "{$path} -> {$encoder->webpPath($path)}  {$width}x{$height} -> {$edge}x{$edge}",
            ];
    }
}
