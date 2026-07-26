<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    // 'public_token' is not fillable: it is generated on create and never supplied.
    protected $fillable = [
        'display_number', 'status', 'payment_method',
        'customer_name', 'phone', 'address', 'floor_bell', 'notes',
        'subtotal', 'delivery_fee', 'total', 'placed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'subtotal' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'placed_at' => 'datetime',
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
}
