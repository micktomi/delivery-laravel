<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'accepting_orders', 'opening_hours', 'closed_message',
    'store_name', 'logo_path', 'brand_primary', 'brand_accent',
])]
final class StoreSetting extends Model
{
    public const SINGLETON_ID = 1;

    public const STORE_NAME_MAX_LENGTH = 120;

    public const DEFAULT_STORE_NAME = 'Delivery Menu';

    public const DEFAULT_BRAND_PRIMARY = '#D97706';

    public const DEFAULT_BRAND_ACCENT = '#1C1206';

    public $timestamps = false;

    /**
     * Fail closed when the singleton ever needs to be recreated.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'accepting_orders' => false,
        'opening_hours' => '[]',
        'store_name' => self::DEFAULT_STORE_NAME,
        'brand_primary' => self::DEFAULT_BRAND_PRIMARY,
        'brand_accent' => self::DEFAULT_BRAND_ACCENT,
    ];

    public static function current(): self
    {
        $settings = self::query()->find(self::SINGLETON_ID);

        if ($settings !== null) {
            return $settings;
        }

        DB::table('store_settings')->insertOrIgnore([
            'id' => self::SINGLETON_ID,
            'accepting_orders' => false,
            'opening_hours' => json_encode([], JSON_THROW_ON_ERROR),
            'closed_message' => null,
            'store_name' => self::DEFAULT_STORE_NAME,
            'logo_path' => null,
            'brand_primary' => self::DEFAULT_BRAND_PRIMARY,
            'brand_accent' => self::DEFAULT_BRAND_ACCENT,
        ]);

        return self::query()->findOrFail(self::SINGLETON_ID);
    }

    protected static function booted(): void
    {
        self::creating(function (self $settings): void {
            $settings->setAttribute($settings->getKeyName(), self::SINGLETON_ID);
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'accepting_orders' => 'boolean',
            'opening_hours' => 'array',
        ];
    }

    /** A DB value may be edited outside Filament, so never render raw CSS. */
    public function brandPrimary(): string
    {
        return self::safeHex($this->brand_primary, self::DEFAULT_BRAND_PRIMARY);
    }

    /** A DB value may be edited outside Filament, so never render raw CSS. */
    public function brandAccent(): string
    {
        return self::safeHex($this->brand_accent, self::DEFAULT_BRAND_ACCENT);
    }

    public function displayName(): string
    {
        $name = trim((string) $this->store_name);

        return $name !== '' && mb_strlen($name) <= self::STORE_NAME_MAX_LENGTH
            ? $name
            : self::DEFAULT_STORE_NAME;
    }

    public function logoUrl(): ?string
    {
        $path = (string) $this->logo_path;

        return self::isSafeLogoPath($path) && Storage::disk('public')->exists($path)
            ? Storage::disk('public')->url($path)
            : null;
    }

    public static function isSafeHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) === 1;
    }

    private static function safeHex(mixed $value, string $default): string
    {
        return self::isSafeHex($value) ? strtoupper($value) : $default;
    }

    private static function isSafeLogoPath(string $path): bool
    {
        return preg_match('/^branding\/[A-Za-z0-9][A-Za-z0-9._-]*\.(?:jpg|jpeg|png|webp)$/Di', $path) === 1;
    }
}
