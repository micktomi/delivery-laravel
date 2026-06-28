<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasLabel, HasColor
{
    case Nea = 'nea';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Out = 'out';
    case Completed = 'completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Nea => 'ΝΕΑ',
            self::Preparing => 'ΕΤΟΙΜΑΖΕΤΑΙ',
            self::Ready => 'ΕΤΟΙΜΟ',
            self::Out => 'ΕΦΥΓΕ',
            self::Completed => 'ΟΛΟΚΛΗΡΩΘΗΚΕ',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Nea => 'warning',
            self::Preparing => 'info',
            self::Ready => 'success',
            self::Out => 'primary',
            self::Completed => 'gray',
        };
    }

    public function nextStatus(): ?self
    {
        return match ($this) {
            self::Nea => self::Preparing,
            self::Preparing => self::Ready,
            self::Ready => self::Out,
            self::Out => self::Completed,
            self::Completed => null,
        };
    }

    public static function orderedStatuses(): array
    {
        return [self::Nea, self::Preparing, self::Ready, self::Out, self::Completed];
    }
}
