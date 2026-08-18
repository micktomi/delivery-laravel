<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

#[Fillable(['accepting_orders', 'opening_hours', 'closed_message'])]
final class StoreSetting extends Model
{
    public const SINGLETON_ID = 1;

    public $timestamps = false;

    /**
     * Fail closed when the singleton ever needs to be recreated.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'accepting_orders' => false,
        'opening_hours' => '[]',
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
}
