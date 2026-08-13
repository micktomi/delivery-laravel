<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Driver extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['name', 'pin', 'is_active'];

    protected $hidden = ['pin'];

    protected function casts(): array
    {
        return [
            'pin' => 'hashed',
            'is_active' => 'boolean',
        ];
    }
}
