<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The two choices the "Γρήγορο Στήσιμο" operator page offers. This is a
 * provisioning-time convenience only — nothing at runtime ever reads this
 * enum or branches on it. Once ApplyQuickSetupPreset has run, the result is
 * just ordinary categories/option groups/option values; the application has
 * no idea a preset was ever involved.
 */
enum QuickSetupPreset: string implements HasLabel
{
    case GrillHouse = 'grill_house';
    case Restaurant = 'restaurant';

    public function getLabel(): string
    {
        return match ($this) {
            self::GrillHouse => 'Grill House',
            self::Restaurant => 'Restaurant',
        };
    }
}
