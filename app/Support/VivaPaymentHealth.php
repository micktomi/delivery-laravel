<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

final class VivaPaymentHealth
{
    public const STALE_PENDING_MINUTES = 30;

    /** @return array{pending: int, inconsistent: int} */
    public function counts(): array
    {
        return [
            'pending' => $this->vivaOrders()
                ->where('payment_status', 'pending')
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->where('created_at', '<', now()->subMinutes(self::STALE_PENDING_MINUTES))
                ->count(),
            'inconsistent' => $this->vivaOrders()
                ->where(function (Builder $query): void {
                    $query
                        ->where(function (Builder $query): void {
                            $query->where('payment_status', 'paid')
                                ->where(function (Builder $query): void {
                                    $query->whereNull('viva_order_code')
                                        ->orWhereNull('viva_transaction_id')
                                        ->orWhereNull('paid_at');
                                });
                        })
                        ->orWhere(function (Builder $query): void {
                            $query->where('payment_status', 'pending')
                                ->where(function (Builder $query): void {
                                    $query->whereNotNull('viva_transaction_id')
                                        ->orWhereNotNull('paid_at');
                                });
                        });
                })
                ->count(),
        ];
    }

    private function vivaOrders(): Builder
    {
        return Order::query()->where('payment_method', PaymentMethod::Viva->value);
    }
}
