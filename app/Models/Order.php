<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    // 'public_token' is not fillable: it is generated on create and never supplied.
    protected $fillable = [
        'display_number', 'status', 'payment_method',
        'payment_status', 'checkout_token',
        'customer_name', 'customer_email', 'phone', 'address', 'floor_bell', 'notes',
        'subtotal', 'delivery_fee', 'total', 'placed_at',
        'coupon_code', 'discount_amount', 'coupon_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'delivery_status' => DeliveryStatus::class,
            'payment_method' => PaymentMethod::class,
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (blank($order->public_token)) {
                $order->public_token = Str::random(40);
            }
        });
    }

    /**
     * Public tracking links are addressed by an unguessable token, never by id.
     */
    public function getRouteKeyName(): string
    {
        return 'public_token';
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function driverTransitions(): HasMany
    {
        return $this->hasMany(OrderDriverTransition::class);
    }

    /**
     * Reporting only. Nothing displayed or totalled may read through this: the
     * money is in coupon_code and discount_amount, snapshotted at submit, and
     * the coupon row may since have been edited or deleted.
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function hasDiscount(): bool
    {
        return (float) $this->discount_amount > 0;
    }

    /**
     * Unconfirmed online orders must not reach the kitchen or a courier.
     * Cash and pay-on-delivery card orders retain their existing behaviour.
     */
    public function scopeReadyForFulfilment(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('payment_method', '!=', PaymentMethod::Viva->value)
                ->orWhere('payment_status', 'paid');
        });
    }

    public function isReadyForFulfilment(): bool
    {
        return $this->payment_method !== PaymentMethod::Viva
            || $this->payment_status === 'paid';
    }
}
