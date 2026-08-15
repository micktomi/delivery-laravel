<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasColor, HasLabel
{
    case Cash = 'cash';
    case PosCourier = 'pos_courier';
    case Viva = 'viva';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'ΜΕΤΡΗΤΑ',
            self::PosCourier => 'ΚΑΡΤΑ ΣΤΟΝ ΔΙΑΝΟΜΕΑ',
            self::Viva => 'ΚΑΡΤΑ ONLINE (VIVA)',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Cash => 'success',
            self::PosCourier => 'info',
            self::Viva => 'warning',
        };
    }
}
