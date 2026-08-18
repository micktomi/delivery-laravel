<?php

namespace App\Filament\Widgets;

use App\Models\StoreSetting;
use App\Support\StoreSchedule;
use Filament\Widgets\Widget;

class StoreOrdersStatus extends Widget
{
    protected static ?int $sort = -10;

    protected static bool $isLazy = false;

    protected static string $view = 'filament.widgets.store-orders-status';

    public function toggleAcceptingOrders(): void
    {
        $settings = StoreSetting::current();

        $settings->update([
            'accepting_orders' => ! $settings->accepting_orders,
        ]);
    }

    /** @return array<string, bool> */
    protected function getViewData(): array
    {
        $settings = StoreSetting::current();

        return [
            'manualAcceptingOrders' => $settings->accepting_orders,
            'isAcceptingOrders' => app(StoreSchedule::class)->isAcceptingOrders(),
        ];
    }
}
