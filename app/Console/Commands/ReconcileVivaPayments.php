<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\VivaWalletService;
use Illuminate\Console\Command;
use Throwable;

class ReconcileVivaPayments extends Command
{
    protected $signature = 'viva:reconcile-pending-payments';

    protected $description = 'Reconcile recent pending Viva payments when a webhook did not arrive.';

    private const MINIMUM_AGE_MINUTES = 5;

    private const WINDOW_MINUTES = 90;

    public function handle(VivaWalletService $viva): int
    {
        if (! (bool) config('services.viva.enabled')) {
            $this->line('Viva payments are disabled; reconciliation skipped.');

            return self::SUCCESS;
        }

        if (! $viva->reconciliationIsConfigured()) {
            $viva->logPaymentEvent('warning', 'viva.reconciliation_not_configured');
            $this->error('Viva reconciliation credentials are not configured.');

            return self::FAILURE;
        }

        $newerThan = now()->subMinutes(self::WINDOW_MINUTES);
        $olderThan = now()->subMinutes(self::MINIMUM_AGE_MINUTES);
        $results = ['paid' => 0, 'pending' => 0, 'duplicate' => 0, 'ignored' => 0, 'failed' => 0];

        Order::query()
            ->where('payment_method', PaymentMethod::Viva->value)
            ->where('payment_status', 'pending')
            ->whereNotNull('viva_order_code')
            ->whereNotIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
            ->whereBetween('created_at', [$newerThan, $olderThan])
            ->orderBy('id')
            ->eachById(function (Order $order) use ($viva, &$results): void {
                try {
                    $result = $viva->reconcilePendingOrder($order);
                    $results[array_key_exists($result, $results) ? $result : 'ignored']++;
                } catch (Throwable $e) {
                    $results['failed']++;
                    $viva->logPaymentEvent('error', 'viva.reconciliation_failed', [
                        'order_id' => $order->getKey(),
                        'exception' => $e::class,
                    ]);
                }
            });

        $this->line(sprintf(
            'Viva reconciliation: %d paid, %d pending, %d duplicate, %d ignored, %d failed.',
            $results['paid'],
            $results['pending'],
            $results['duplicate'],
            $results['ignored'],
            $results['failed'],
        ));

        return $results['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
