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
            self::Cash => 'Μετρητά',
            self::PosCourier => 'POS στον courier',
            self::Viva => 'Viva Wallet',
        };
    }

    public function trackingLabel(?string $paymentStatus): string
    {
        return match ($this) {
            self::Cash => 'Μετρητά κατά την παράδοση',
            self::PosCourier => 'POS στον courier',
            self::Viva => $paymentStatus === 'paid'
                ? 'Viva Wallet — Πληρωμένο'
                : 'Viva Wallet — Αναμονή επιβεβαίωσης',
        };
    }

    public function courierInstruction(?string $paymentStatus, string $formattedTotal): string
    {
        return match ($this) {
            self::Cash => 'ΕΙΣΠΡΑΞΗ ΜΕΤΡΗΤΩΝ: '.$formattedTotal,
            self::PosCourier => 'ΠΛΗΡΩΜΗ ΜΕ POS: '.$formattedTotal,
            self::Viva => $paymentStatus === 'paid'
                ? 'ΠΛΗΡΩΜΕΝΟ — ΜΗΝ ΕΙΣΠΡΑΞΕΙΣ'
                : 'ΠΛΗΡΩΜΗ ΣΕ ΑΝΑΜΟΝΗ',
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
