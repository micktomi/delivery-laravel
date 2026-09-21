<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Services\VivaWalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class ResolveAmbiguousVivaPayment extends Command
{
    protected $signature = 'viva:resolve-ambiguous-payment
        {order : Local order ID}
        {--order-code= : Existing 16-digit Viva order code found in the dashboard}
        {--not-created : Confirm that Viva did not create a payment order}';

    protected $description = 'Resolve an ambiguous Viva payment-order creation after checking the Viva dashboard.';

    public function handle(VivaWalletService $viva): int
    {
        $orderId = trim((string) $this->argument('order'));
        $orderCode = trim((string) $this->option('order-code'));
        $notCreated = (bool) $this->option('not-created');

        if (! ctype_digit($orderId) || (int) $orderId < 1) {
            $this->error('A valid local order ID is required.');

            return self::FAILURE;
        }

        if (($orderCode !== '') === $notCreated) {
            $this->error('Choose exactly one resolution: --order-code or --not-created.');

            return self::FAILURE;
        }

        if ($orderCode !== '' && preg_match('/^\d{16}$/D', $orderCode) !== 1) {
            $this->error('The Viva order code must contain exactly 16 digits.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($orderId, $orderCode): void {
                $order = Order::query()->whereKey((int) $orderId)->lockForUpdate()->first();

                if (! $order) {
                    throw new LogicException('The local order was not found.');
                }

                if ($order->payment_method !== PaymentMethod::Viva
                    || $order->payment_status !== VivaWalletService::PAYMENT_ORDER_OUTCOME_UNKNOWN
                    || filled($order->viva_order_code)) {
                    throw new LogicException('The order does not have an unresolved Viva payment-order outcome.');
                }

                if ($order->status === OrderStatus::Completed) {
                    throw new LogicException('A completed order cannot enter Viva reconciliation.');
                }

                if ($orderCode !== '' && Order::query()
                    ->where('viva_order_code', $orderCode)
                    ->where('id', '!=', $order->getKey())
                    ->exists()) {
                    throw new LogicException('That Viva order code is already assigned to another local order.');
                }

                $order->forceFill([
                    'payment_status' => 'pending',
                    'viva_order_code' => $orderCode !== '' ? $orderCode : null,
                ])->save();
            });
        } catch (LogicException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $viva->logPaymentEvent('error', 'viva.payment_order_outcome_resolution_failed', [
                'order_id' => (int) $orderId,
                'exception' => $e::class,
            ]);
            $this->error('The ambiguous Viva payment could not be resolved. No state was changed.');

            return self::FAILURE;
        }

        $resolution = $orderCode !== '' ? 'linked_existing_order' : 'confirmed_not_created';
        $viva->logPaymentEvent('info', 'viva.payment_order_outcome_resolved', [
            'order_id' => (int) $orderId,
            'resolution' => $resolution,
        ]);
        $this->info('The ambiguous Viva payment-order outcome was resolved.');

        return self::SUCCESS;
    }
}
