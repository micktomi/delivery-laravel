<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDriverTransition extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id', 'driver_id', 'from_status', 'to_status', 'transitioned_at',
    ];

    protected function casts(): array
    {
        return ['transitioned_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}
