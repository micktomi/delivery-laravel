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

    protected $description = 'Reconcile pending Viva payments when a webhook did not arrive.';

    private const MINIMUM_AGE_MINUTES = 5;

    public function handle(VivaWalletService $viva): int
    {
        $enabled = (bool) config('services.viva.enabled');

        if (! $viva->reconciliationIsConfigured()) {
            // Viva off and the credentials gone is a decommissioned
            // integration, not a misconfiguration to fail on every five
            // minutes. Missing credentials while Viva is live still is.
            if (! $enabled) {
                $this->line('Viva payments are disabled; reconciliation skipped.');

                return self::SUCCESS;
            }

            $viva->logPaymentEvent('warning', 'viva.reconciliation_not_configured');
            $this->error('Viva reconciliation credentials are not configured.');

            return self::FAILURE;
        }

        // The switch stops new payments, not payments already in flight. Every
        // candidate below already reached Smart Checkout and has a payment
        // order code, and nothing on this path can create one, so a disabled
        // integration still lands money that moved before it was closed.
        if (! $enabled) {
            $this->line('Viva payments are disabled; reconciling payments already in flight.');
        }

        $olderThan = now()->subMinutes(self::MINIMUM_AGE_MINUTES);
        $results = ['paid' => 0, 'pending' => 0, 'duplicate' => 0, 'expired' => 0, 'ignored' => 0, 'failed' => 0];

        Order::query()
            ->where('payment_method', PaymentMethod::Viva->value)
            ->where('payment_status', 'pending')
            ->whereNotNull('viva_order_code')
            ->where('status', '!=', OrderStatus::Completed->value)
            ->where('created_at', '<=', $olderThan)
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
            'Viva reconciliation: %d paid, %d pending, %d duplicate, %d expired, %d ignored, %d failed.',
            $results['paid'],
            $results['pending'],
            $results['duplicate'],
            $results['expired'],
            $results['ignored'],
            $results['failed'],
        ));

        return $results['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
