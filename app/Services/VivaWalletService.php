<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class VivaWalletService
{
    private const PAYMENT_CREATED_EVENT = 1796;

    private const EURO_CURRENCY_CODE = '978';

    public function createPaymentOrder(Order $order): string
    {
        if (! (bool) config('services.viva.enabled')) {
            throw new RuntimeException('Viva payments are disabled.');
        }

        $amount = $this->orderTotalInCents($order);

        if ($amount < 30) {
            throw new RuntimeException('The order total is below the Viva minimum amount.');
        }

        $response = $this->apiClient()->post('/checkout/v2/orders', [
            'amount' => $amount,
            'sourceCode' => $this->configuredValue('source_code'),
            'customerTrns' => 'Παραγγελία #'.$order->display_number,
            'merchantTrns' => 'Delivery order '.$order->getKey(),
            'customer' => [
                'fullName' => Str::limit($order->customer_name, 100, ''),
                'phone' => $order->phone,
                'countryCode' => 'GR',
                'requestLang' => 'el-GR',
            ],
        ])->throw();

        $orderCode = (string) $response->json('orderCode', '');

        if (! preg_match('/^\d{1,32}$/D', $orderCode)) {
            throw new RuntimeException('Viva returned an invalid payment order code.');
        }

        $this->logPaymentEvent('info', 'viva.payment_order_created', [
            'order_id' => $order->getKey(),
            'viva_order_code' => $orderCode,
            'amount_cents' => $amount,
            'environment' => $this->isDemo() ? 'demo' : 'production',
        ]);

        return $orderCode;
    }

    public function checkoutUrl(string $orderCode): string
    {
        if (! preg_match('/^\d{1,32}$/D', $orderCode)) {
            throw new RuntimeException('Invalid Viva payment order code.');
        }

        $host = $this->isDemo()
            ? 'https://demo.vivapayments.com'
            : 'https://www.vivapayments.com';

        return $host.'/web/checkout?ref='.rawurlencode($orderCode);
    }

    /**
     * The webhook body is only a trigger. A payment is accepted solely from a
     * fresh, OAuth-authenticated Retrieve Transaction response from Viva.
     */
    public function processWebhook(array $payload): string
    {
        if ((int) data_get($payload, 'EventTypeId', 0) !== self::PAYMENT_CREATED_EVENT) {
            return 'ignored';
        }

        $transactionId = strtolower((string) data_get($payload, 'EventData.TransactionId', ''));
        $reportedOrderCode = (string) data_get($payload, 'EventData.OrderCode', '');

        if (! Str::isUuid($transactionId)
            || ! preg_match('/^\d{1,32}$/D', $reportedOrderCode)
            || ! Order::query()
                ->where('viva_order_code', $reportedOrderCode)
                ->where('payment_method', PaymentMethod::Viva->value)
                ->exists()) {
            return 'ignored';
        }

        $transaction = $this->retrieveTransaction($transactionId);
        $retrievedOrderCode = (string) data_get($transaction, 'orderCode', '');

        if (! hash_equals($retrievedOrderCode, $reportedOrderCode)) {
            $this->logPaymentEvent('warning', 'viva.webhook_order_mismatch', ['transaction_id' => $transactionId]);

            return 'ignored';
        }

        return $this->confirmTransaction($transactionId, $transaction);
    }

    public function retrieveTransaction(string $transactionId): array
    {
        if (! Str::isUuid($transactionId)) {
            throw new RuntimeException('Invalid Viva transaction ID.');
        }

        $transaction = $this->apiClient()
            ->get('/checkout/v2/transactions/'.$transactionId)
            ->throw()
            ->json();

        if (! is_array($transaction)) {
            throw new RuntimeException('Viva returned an invalid transaction response.');
        }

        return $transaction;
    }

    /**
     * Reconciles a pending payment order after a missed or failed webhook.
     *
     * Viva's OAuth Retrieve Transaction endpoint requires a transaction ID.
     * The legacy Retrieve Order endpoint gives us that ID for a payment order,
     * after which the exact same confirmation path as the webhook is used.
     */
    public function reconcilePendingOrder(Order $order): string
    {
        if (! $this->isReconciliationCandidate($order)) {
            return 'ignored';
        }

        $orderCode = (string) $order->viva_order_code;
        $transactionId = $this->retrieveOrderTransactionId($orderCode, $order->getKey());

        if ($transactionId === null) {
            return 'pending';
        }

        $transaction = $this->retrieveTransaction($transactionId);
        $retrievedOrderCode = (string) data_get($transaction, 'orderCode', '');

        if (! hash_equals($orderCode, $retrievedOrderCode)) {
            $this->logPaymentEvent('warning', 'viva.reconciliation_order_mismatch', [
                'order_id' => $order->getKey(),
                'transaction_id' => $transactionId,
            ]);

            return 'ignored';
        }

        // Unlike a webhook, reconciliation intentionally leaves terminal
        // orders untouched. A late webhook still retains the existing refund
        // / manual-review signal for a payment after cancellation.
        return $this->confirmTransaction($transactionId, $transaction, skipTerminalOrders: true);
    }

    public function reconciliationIsConfigured(): bool
    {
        return trim((string) config('services.viva.reconciliation_merchant_id')) !== ''
            && trim((string) config('services.viva.reconciliation_api_key')) !== '';
    }

    private function confirmTransaction(
        string $transactionId,
        array $transaction,
        bool $skipTerminalOrders = false,
    ): string {
        $orderCode = (string) data_get($transaction, 'orderCode', '');
        $status = (string) data_get($transaction, 'statusId', '');
        $currency = (string) data_get($transaction, 'currencyCode', '');
        $paidCents = $this->amountInCents(data_get($transaction, 'amount'));

        if (! preg_match('/^\d{1,32}$/D', $orderCode)
            || $status !== 'F'
            || $currency !== self::EURO_CURRENCY_CODE
            || $paidCents === null) {
            $this->logPaymentEvent('warning', 'viva.transaction_not_payable', [
                'transaction_id' => $transactionId,
                'status' => $status,
                'currency' => $currency,
            ]);

            return 'ignored';
        }

        return DB::transaction(function () use ($transactionId, $orderCode, $paidCents, $skipTerminalOrders): string {
            $order = Order::query()
                ->where('viva_order_code', $orderCode)
                ->lockForUpdate()
                ->first();

            if (! $order || $order->payment_method !== PaymentMethod::Viva) {
                $this->logPaymentEvent('warning', 'viva.transaction_order_not_found', ['transaction_id' => $transactionId]);

                return 'ignored';
            }

            if ($skipTerminalOrders && in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
                $this->logPaymentEvent('warning', 'viva.reconciliation_terminal_order_skipped', [
                    'order_id' => $order->getKey(),
                    'transaction_id' => $transactionId,
                    'status' => $order->status->value,
                ]);

                return 'ignored';
            }

            if ($paidCents !== $this->orderTotalInCents($order)) {
                $this->logPaymentEvent('warning', 'viva.transaction_amount_mismatch', [
                    'order_id' => $order->getKey(),
                    'transaction_id' => $transactionId,
                    'paid_cents' => $paidCents,
                    'expected_cents' => $this->orderTotalInCents($order),
                ]);

                return 'ignored';
            }

            if ($order->payment_status === 'paid') {
                if (hash_equals((string) $order->viva_transaction_id, $transactionId)) {
                    $this->logPaymentEvent('info', 'viva.webhook_duplicate', [
                        'order_id' => $order->getKey(),
                        'transaction_id' => $transactionId,
                    ]);

                    return 'duplicate';
                }

                $this->logPaymentEvent('warning', 'viva.paid_order_transaction_mismatch', [
                    'order_id' => $order->getKey(),
                    'transaction_id' => $transactionId,
                ]);

                return 'ignored';
            }

            $transactionAlreadyUsed = Order::query()
                ->where('viva_transaction_id', $transactionId)
                ->where('id', '!=', $order->getKey())
                ->exists();

            if ($transactionAlreadyUsed) {
                $this->logPaymentEvent('warning', 'viva.transaction_already_used', ['transaction_id' => $transactionId]);

                return 'ignored';
            }

            $order->forceFill([
                'payment_status' => 'paid',
                'viva_transaction_id' => $transactionId,
                'paid_at' => now(),
            ])->save();

            if ($order->status === OrderStatus::Cancelled) {
                $this->logPaymentEvent('critical', 'viva.payment_received_after_cancellation', [
                    'order_id' => $order->getKey(),
                    'viva_order_code' => $orderCode,
                    'transaction_id' => $transactionId,
                    'action_required' => 'refund_or_manual_review',
                ]);

                return 'paid';
            }

            $this->logPaymentEvent('info', 'viva.payment_confirmed', [
                'order_id' => $order->getKey(),
                'transaction_id' => $transactionId,
            ]);

            return 'paid';
        });
    }

    private function isReconciliationCandidate(Order $order): bool
    {
        return $order->payment_method === PaymentMethod::Viva
            && $order->payment_status === 'pending'
            && filled($order->viva_order_code)
            && ! in_array($order->status, [OrderStatus::Completed, OrderStatus::Cancelled], true);
    }

    private function retrieveOrderTransactionId(string $orderCode, int|string $localOrderId): ?string
    {
        if (! preg_match('/^\d{1,32}$/D', $orderCode)) {
            $this->logPaymentEvent('warning', 'viva.reconciliation_invalid_order_code', [
                'order_id' => $localOrderId,
            ]);

            return null;
        }

        $paymentOrder = $this->reconciliationApiClient()
            ->get('/api/orders/'.$orderCode)
            ->throw()
            ->json();

        if (! is_array($paymentOrder)) {
            throw new RuntimeException('Viva returned an invalid payment order response.');
        }

        $returnedOrderCode = (string) data_get(
            $paymentOrder,
            'OrderCode',
            data_get($paymentOrder, 'orderCode', ''),
        );

        if (! hash_equals($orderCode, $returnedOrderCode)) {
            $this->logPaymentEvent('warning', 'viva.reconciliation_payment_order_mismatch', [
                'order_id' => $localOrderId,
            ]);

            return null;
        }

        $transactionId = strtolower((string) data_get(
            $paymentOrder,
            'TransactionId',
            data_get($paymentOrder, 'transactionId', ''),
        ));

        if ($transactionId === '') {
            return null;
        }

        if (! Str::isUuid($transactionId)) {
            $this->logPaymentEvent('warning', 'viva.reconciliation_invalid_transaction_id', [
                'order_id' => $localOrderId,
            ]);

            return null;
        }

        return $transactionId;
    }

    /**
     * Payment logging is best effort: an unavailable log destination must not
     * roll back or mask an otherwise valid payment state transition.
     */
    public function logPaymentEvent(string $level, string $event, array $context = []): void
    {
        try {
            Log::channel('payments')->log($level, $event, $context);
        } catch (Throwable $e) {
            try {
                Log::error('payments.log_write_failed', [
                    'payment_event' => $event,
                    'exception' => $e::class,
                ]);
            } catch (Throwable) {
                // Logging cannot be allowed to change payment truth.
            }
        }
    }

    private function apiClient(): PendingRequest
    {
        return Http::baseUrl($this->apiBaseUrl())
            ->acceptJson()
            ->asJson()
            ->withToken($this->accessToken())
            ->timeout(10);
    }

    private function reconciliationApiClient(): PendingRequest
    {
        return Http::baseUrl($this->checkoutBaseUrl())
            ->acceptJson()
            ->withBasicAuth(
                $this->configuredValue('reconciliation_merchant_id'),
                $this->configuredValue('reconciliation_api_key'),
            )
            ->timeout(10);
    }

    private function accessToken(): string
    {
        $clientId = $this->configuredValue('client_id');
        $cacheKey = 'viva.oauth.'.hash('sha256', ($this->isDemo() ? 'demo:' : 'production:').$clientId);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->acceptJson()
            ->withBasicAuth($clientId, $this->configuredValue('client_secret'))
            ->timeout(10)
            ->post($this->accountsBaseUrl().'/connect/token', [
                'grant_type' => 'client_credentials',
            ])
            ->throw();

        $token = (string) $response->json('access_token', '');
        $expiresIn = (int) $response->json('expires_in', 0);

        if ($token === '') {
            throw new RuntimeException('Viva returned an invalid OAuth response.');
        }

        if ($expiresIn > 60) {
            Cache::put($cacheKey, $token, $expiresIn - 60);
        }

        return $token;
    }

    private function configuredValue(string $key): string
    {
        $value = trim((string) config('services.viva.'.$key));

        if ($value === '') {
            throw new RuntimeException('Viva configuration is incomplete.');
        }

        return $value;
    }

    private function isDemo(): bool
    {
        $environment = strtolower((string) config('services.viva.environment', 'demo'));

        if (! in_array($environment, ['demo', 'production', 'live'], true)) {
            throw new RuntimeException('Invalid Viva environment.');
        }

        return $environment === 'demo';
    }

    private function accountsBaseUrl(): string
    {
        return $this->isDemo()
            ? 'https://demo-accounts.vivapayments.com'
            : 'https://accounts.vivapayments.com';
    }

    private function apiBaseUrl(): string
    {
        return $this->isDemo()
            ? 'https://demo-api.vivapayments.com'
            : 'https://api.vivapayments.com';
    }

    private function checkoutBaseUrl(): string
    {
        return $this->isDemo()
            ? 'https://demo.vivapayments.com'
            : 'https://www.vivapayments.com';
    }

    private function orderTotalInCents(Order $order): int
    {
        return (int) round((float) $order->total * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function amountInCents(mixed $amount): ?int
    {
        if (! is_numeric($amount)) {
            return null;
        }

        $cents = (int) round((float) $amount * 100, 0, PHP_ROUND_HALF_UP);

        return $cents >= 0 ? $cents : null;
    }
}
