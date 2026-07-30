<?php

namespace App\Models;

use App\Enums\CouponType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    // 'used_count' is not fillable: it only ever moves through claim().
    protected $fillable = [
        'code', 'type', 'value', 'min_order_total',
        'starts_at', 'expires_at', 'max_uses', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => 'decimal:2',
            'min_order_total' => 'decimal:2',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Codes are handed out at the counter and typed back by hand, so they are
     * stored uppercase and every lookup normalises the same way.
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => self::normalizeCode($value),
        );
    }

    public static function normalizeCode(?string $code): string
    {
        return mb_strtoupper(trim((string) $code));
    }

    public static function findByCode(?string $code): ?self
    {
        $code = self::normalizeCode($code);

        return $code === '' ? null : self::where('code', $code)->first();
    }

    /**
     * Why this coupon cannot be used right now, in words the customer sees, or
     * null when it can. Checked in the cart and again at submit.
     */
    public function rejectionReason(float $subtotal): ?string
    {
        if (! $this->is_active) {
            return 'Ο κωδικός δεν είναι ενεργός.';
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'Ο κωδικός δεν ισχύει ακόμη.';
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'Ο κωδικός έχει λήξει.';
        }

        if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
            return 'Ο κωδικός έχει εξαντληθεί.';
        }

        if ($this->min_order_total !== null && $subtotal < (float) $this->min_order_total) {
            return 'Ο κωδικός ισχύει για παραγγελίες από '
                .number_format((float) $this->min_order_total, 2, ',', '.').'€.';
        }

        return null;
    }

    public function isRedeemableFor(float $subtotal): bool
    {
        return $this->rejectionReason($subtotal) === null;
    }

    /**
     * Take one use atomically. Two customers redeeming the last use at the same
     * moment both pass a read-then-write check, so the cap is enforced by the
     * WHERE clause of the UPDATE itself: the loser gets 0 rows back.
     */
    public function claim(): bool
    {
        $claimed = static::whereKey($this->getKey())
            ->where(fn ($q) => $q->whereNull('max_uses')
                ->orWhereColumn('used_count', '<', 'max_uses'))
            ->increment('used_count');

        if ($claimed > 0) {
            $this->used_count = $this->used_count + 1;
        }

        return $claimed > 0;
    }
}
