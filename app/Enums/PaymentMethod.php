<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel, HasColor
{
    case Cash = 'cash';
    case PosCourier = 'pos_courier';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'ΜΕΤΡΗΤΑ',
            self::PosCourier => 'ΚΑΡΤΑ ΣΤΟΝ ΔΙΑΝΟΜΕΑ',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Cash => 'success',
            self::PosCourier => 'info',
        };
    }
}
