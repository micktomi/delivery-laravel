<?php

namespace App\Filament\Resources\OrderResource\Widgets;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deliberately not coupled to the OrderResource table's own date filter:
 * that filter lives in Livewire's reactive-prop machinery, and reading it
 * from here (via InteractsWithPageTable) desynced from what the table
 * actually rendered and crashed on hydration. This widget owns a single
 * plain string property instead — no reactive props, nothing to hydrate as
 * null — and labels the date it's summarising so it's never mistaken for
 * whatever the table below happens to be filtered to.
 */
class DailyOrderStatsWidget extends Widget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.resources.order-resource.widgets.daily-order-stats-widget';

    public string $date = '';

    public function mount(): void
    {
        $this->date = today()->toDateString();
    }

    /**
     * A native date input reports an empty string when cleared; there is
     * always a concrete day to summarise, so fall back to today rather than
     * querying with a blank date.
     */
    public function updatedDate(string $value): void
    {
        if (blank($value)) {
            $this->date = today()->toDateString();
        }
    }

    /**
     * @return array{total: int, completed_or_out: int, cancelled: int, revenue: float}
     */
    public function getStats(): array
    {
        $date = blank($this->date) ? today()->toDateString() : $this->date;

        $query = fn (): Builder => Order::query()->whereDate('created_at', $date);

        return [
            'total' => $query()->count(),
            'completed_or_out' => $query()->whereIn('status', [
                OrderStatus::Completed->value,
                OrderStatus::Out->value,
            ])->count(),
            'cancelled' => $query()->where('status', OrderStatus::Cancelled->value)->count(),
            'revenue' => (float) $query()->where('status', '!=', OrderStatus::Cancelled->value)->sum('total'),
        ];
    }
}
