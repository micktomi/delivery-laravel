<?php

namespace App\Filament\Widgets;

use App\Support\VivaPaymentHealth;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class VivaPaymentHealthWidget extends BaseWidget
{
    protected static ?int $sort = -5;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $counts = app(VivaPaymentHealth::class)->counts();

        return [
            Stat::make('Viva: εκκρεμείς πληρωμές', $counts['pending'])
                ->description($counts['pending'] > 0
                    ? 'Σε αναμονή πάνω από 30 λεπτά'
                    : 'Καμία εκκρεμότητα')
                ->color($counts['pending'] > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-clock'),

            Stat::make('Viva: ασυνέπειες πληρωμών', $counts['inconsistent'])
                ->description($counts['inconsistent'] > 0
                    ? 'Χρειάζονται ανθρώπινο έλεγχο'
                    : 'Καμία ασυνέπεια')
                ->color($counts['inconsistent'] > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-exclamation-triangle'),
        ];
    }
}
