<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\VivaApiException;
use App\Exceptions\VivaConfigurationException;
use App\Exceptions\VivaException;
use App\Exceptions\VivaPaymentOrderCancellationException;
use App\Exceptions\VivaResponseException;
use App\Exceptions\VivaTransportException;
use App\Models\Order;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class VivaWalletService
{
    public const PAYMENT_ORDER_OUTCOME_UNKNOWN = 'payment_order_unknown';

    /**
     * Terminal local payment state for a payment order Viva will never accept
     * money for again. It exists so an abandoned checkout stops being a
     * reconciliation candidate instead of being polled every five minutes for
     * the rest of the installation's life.
     */
    public const PAYMENT_STATUS_EXPIRED = 'expired';

    private const PAYMENT_CREATED_EVENT = 1796;

    private const EURO_CURRENCY_CODE = '978';

    private const HTTP_TIMEOUT_SECONDS = 10;

    /** Payment order StateId values of the Retrieve order API. */
    private const PAYMENT_ORDER_PENDING = 0;

    private const PAYMENT_ORDER_EXPIRED = 1;

    private const PAYMENT_ORDER_CANCELED = 2;

    private const PAYMENT_ORDER_PAID = 3;

    public function createPaymentOrder(Order $order): string
    {
        if (! (bool) config('services.viva.enabled')) {
            throw new VivaConfigurationException('Viva payments are disabled.');
        }

        $amount = $this->orderTotalInCents($order);

        if ($amount < 30) {
            throw new VivaException('The order total is below the Viva minimum amount.');
        }

        $endpoint = '/checkout/v2/orders';
        $response = $this->sendRequest(
            fn (): Response => $this->apiClient()->post($endpoint, [
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
            ]),
            $endpoint,
        );
        $payload = $this->jsonObject(
            $this->throwOnFailure($response, $endpoint, forgetAccessTokenOnUnauthorized: true),
            $endpoint,
        );
        $orderCode = $this->stringField($payload, 'orderCode');

        if (! $this->isOrderCode($orderCode)) {
            throw new VivaResponseException('Viva returned an invalid payment order code.');
        }

        $this->logPaymentEvent('info', 'viva.payment_order_created', [
            'order_id' => $order->getKey(),
            'amount_cents' => $amount,
            'environment' => $this->isDemo() ? 'demo' : 'production',
        ]);

        return $orderCode;
    }

    public function checkoutUrl(string $orderCode): string
    {
        if (! $this->isOrderCode($orderCode)) {
            throw new VivaException('Invalid Viva payment order code.');
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
        $eventTypeId = $payload['EventTypeId'] ?? null;
        if ($eventTypeId !== self::PAYMENT_CREATED_EVENT && $eventTypeId !== (string) self::PAYMENT_CREATED_EVENT) {
            return 'ignored';
        }

        $eventData = $payload['EventData'] ?? null;
        if (! is_array($eventData)) {
            return 'ignored';
        }

        $transactionId = $eventData['TransactionId'] ?? null;
        $reportedOrderCode = $eventData['OrderCode'] ?? null;

        if (! is_string($transactionId) || (! is_string($reportedOrderCode) && ! is_int($reportedOrderCode))) {
            return 'ignored';
        }

        $transactionId = strtolower(trim($transactionId));
        $reportedOrderCode = trim((string) $reportedOrderCode);

        if (! Str::isUuid($transactionId)
            || ! $this->isOrderCode($reportedOrderCode)
            || ! Order::query()
                ->where('viva_order_code', $reportedOrderCode)
                ->where('payment_method', PaymentMethod::Viva->value)
                ->exists()) {
            return 'ignored';
        }

        $transaction = $this->retrieveTransaction($transactionId);
        $retrievedOrderCode = (string) data_get($transaction, 'orderCode', '');

        if (! hash_equals($retrievedOrderCode, $reportedOrderCode)) {
            $this->logPaymentEvent('warning', 'viva.webhook_order_mismatch');

            return 'ignored';
        }

        return $this->confirmTransaction($transactionId, $transaction);
    }

    public function retrieveTransaction(string $transactionId): array
    {
        if (! Str::isUuid($transactionId)) {
            throw new VivaException('Invalid Viva transaction ID.');
        }

        $requestPath = '/checkout/v2/transactions/'.$transactionId;
        $endpoint = '/checkout/v2/transactions/{transactionId}';
        $response = $this->sendRequest(fn (): Response => $this->apiClient()->get($requestPath), $endpoint);

        return $this->jsonObject(
            $this->throwOnFailure($response, $endpoint, forgetAccessTokenOnUnauthorized: true),
            $endpoint,
        );
    }

    /**
     * Reconciles a pending payment order after a missed or failed webhook.
     *
     * Viva's OAuth Retrieve Transaction endpoint requires a transaction ID.
     * The legacy transaction search returns IDs for the stored payment order,
     * after which the exact same confirmation path as the webhook is used.
     */
    public function reconcilePendingOrder(Order $order): string
    {
        if (! $this->isReconciliationCandidate($order)) {
            return 'ignored';
        }

        $orderCode = (string) $order->viva_order_code;
        $transactionIds = $this->retrieveOrderTransactionIds($orderCode, $order->getKey());
        $result = 'pending';

        foreach ($transactionIds as $transactionId) {
            $transaction = $this->retrieveTransaction($transactionId);
            $retrievedOrderCode = (string) data_get($transaction, 'orderCode', '');

            if (! hash_equals($orderCode, $retrievedOrderCode)) {
                $this->logPaymentEvent('warning', 'viva.reconciliation_order_mismatch', [
                    'order_id' => $order->getKey(),
                ]);
                $result = 'ignored';

                continue;
            }

            // The lookup only supplies candidate IDs. Payment truth still
            // comes from Retrieve Transaction, including after cancellation.
            $result = $this->confirmTransaction($transactionId, $transaction, skipCompletedOrders: true);

            if (in_array($result, ['paid', 'duplicate'], true)) {
                return $result;
            }
        }

        // No money arrived for this order. If Viva will not take any either,
        // the order has to leave the candidate set: nothing else ever would.
        return $this->expireUnpayablePaymentOrder($order, $orderCode) ? 'expired' : $result;
    }

    /**
     * Retires a payment order Viva reports as expired or cancelled. Only the
     * local payment state moves; the order's own status is never touched, so a
     * terminal order stays exactly as the kitchen left it.
     */
    private function expireUnpayablePaymentOrder(Order $order, string $orderCode): bool
    {
        $state = $this->reconciliationPaymentOrderState($orderCode, $order->getKey());

        if ($state !== self::PAYMENT_ORDER_EXPIRED && $state !== self::PAYMENT_ORDER_CANCELED) {
            return false;
        }

        // Conditional update: a payment confirmed between the lookup and here
        // owns the row, and this must never overwrite it.
        $expired = Order::query()
            ->whereKey($order->getKey())
            ->where('payment_status', 'pending')
            ->where('viva_order_code', $orderCode)
            ->update(['payment_status' => self::PAYMENT_STATUS_EXPIRED]);

        if ($expired !== 1) {
            return false;
        }

        $this->logPaymentEvent('info', 'viva.payment_order_expired', [
            'order_id' => $order->getKey(),
            'state' => $state,
        ]);

        return true;
    }

    /**
     * Best-effort read of the remote payment-order state during reconciliation.
     * Any answer that is not a clean, matching state returns null and the order
     * simply stays a candidate for the next run: a transport blip must never
     * retire an order the customer can still pay.
     */
    private function reconciliationPaymentOrderState(string $orderCode, int|string $localOrderId): ?int
    {
        $endpoint = '/api/orders/{orderCode}';

        try {
            $response = $this->sendRequest(
                fn (): Response => $this->reconciliationApiClient()->get('/api/orders/'.$orderCode),
                $endpoint,
            );
        } catch (VivaTransportException) {
            return null;
        }

        if ($this->httpFailureReason($response) !== null) {
            $this->logPaymentEvent('warning', 'viva.reconciliation_order_state_unavailable', [
                'order_id' => $localOrderId,
                'http_status' => $response->status(),
            ]);

            return null;
        }

        $payload = $response->json();
        $returnedOrderCode = (string) data_get($payload, 'OrderCode', '');
        $state = data_get($payload, 'StateId');

        if (! is_array($payload)
            || ! hash_equals($orderCode, $returnedOrderCode)
            || ! $this->isPaymentOrderState($state)) {
            $this->logPaymentEvent('warning', 'viva.reconciliation_invalid_order_state', [
                'order_id' => $localOrderId,
            ]);

            return null;
        }

        return (int) $state;
    }

    public function reconciliationIsConfigured(): bool
    {
        return trim((string) config('services.viva.reconciliation_merchant_id')) !== ''
            && trim((string) config('services.viva.reconciliation_api_key')) !== '';
    }

    /**
     * Makes a standing payment order unpayable at Viva before the local order
     * is cancelled. Official Payment API (developer.viva.com, payment-api.yaml),
     * both Basic-authenticated with the Merchant ID and API key:
     *
     *   GET    /api/orders/{orderCode}  StateId 0 Pending, 1 Expired, 2 Canceled, 3 Paid
     *   DELETE /api/orders/{orderCode}  200 {Success: true, ErrorCode: 0}; 401; 404 unknown code; 5xx
     *
     * Returns 'cancelled', 'already_cancelled' or 'expired': the three states in
     * which the customer can no longer pay. Anything else — paid, unreachable,
     * unknown at Viva, or a response that does not say Success — throws, and
     * the caller must leave the local order exactly as it is. Never called
     * inside a database transaction.
     *
     * @throws VivaPaymentOrderCancellationException
     */
    public function cancelPaymentOrder(Order $order): string
    {
        $orderCode = (string) $order->viva_order_code;

        if (! $this->isOrderCode($orderCode)) {
            throw $this->cancellationFailed($order, 'invalid_order_code');
        }

        if (! $this->reconciliationIsConfigured()) {
            throw $this->cancellationFailed($order, 'unconfigured');
        }

        try {
            $state = $this->retrievePaymentOrderState($order, $orderCode);

            if ($state === self::PAYMENT_ORDER_PAID) {
                throw $this->cancellationFailed($order, 'paid');
            }

            if ($state === self::PAYMENT_ORDER_CANCELED || $state === self::PAYMENT_ORDER_EXPIRED) {
                $result = $state === self::PAYMENT_ORDER_CANCELED ? 'already_cancelled' : 'expired';

                $this->logPaymentEvent('info', 'viva.payment_order_already_unpayable', [
                    'order_id' => $order->getKey(),
                    'result' => $result,
                ]);

                return $result;
            }

            if ($state !== self::PAYMENT_ORDER_PENDING) {
                throw $this->cancellationFailed($order, 'ambiguous', ['state' => $state]);
            }

            $response = $this->reconciliationApiClient()->delete('/api/orders/'.$orderCode);
        } catch (VivaPaymentOrderCancellationException $e) {
            throw $e;
        } catch (ConnectionException $e) {
            throw $this->cancellationFailed($order, 'unreachable', ['exception' => $e::class]);
        } catch (Throwable $e) {
            throw $this->cancellationFailed($order, 'error', ['exception' => $e::class]);
        }

        if ($reason = $this->httpFailureReason($response)) {
            throw $this->cancellationFailed($order, $reason, ['http_status' => $response->status()]);
        }

        if ($response->json('Success') !== true || (int) $response->json('ErrorCode', -1) !== 0) {
            throw $this->cancellationFailed($order, 'rejected', [
                'error_code' => $response->json('ErrorCode'),
                'event_id' => $response->json('EventId'),
            ]);
        }

        $this->logPaymentEvent('info', 'viva.payment_order_cancelled', [
            'order_id' => $order->getKey(),
        ]);

        return 'cancelled';
    }

    /**
     * @throws VivaPaymentOrderCancellationException
     */
    private function retrievePaymentOrderState(Order $order, string $orderCode): int
    {
        $response = $this->reconciliationApiClient()->get('/api/orders/'.$orderCode);

        if ($reason = $this->httpFailureReason($response)) {
            throw $this->cancellationFailed($order, $reason, ['http_status' => $response->status()]);
        }

        $payload = $response->json();
        $returnedOrderCode = (string) data_get($payload, 'OrderCode', '');
        $state = data_get($payload, 'StateId');

        if (! is_array($payload) || ! hash_equals($orderCode, $returnedOrderCode) || ! is_numeric($state)) {
            throw $this->cancellationFailed($order, 'ambiguous');
        }

        return (int) $state;
    }

    private function httpFailureReason(Response $response): ?string
    {
        return match (true) {
            $response->status() === 401 => 'unauthorized',
            $response->status() === 404 => 'not_found',
            $response->serverError() => 'provider_error',
            ! $response->ok() => 'ambiguous',
            default => null,
        };
    }

    private function cancellationFailed(
        Order $order,
        string $reason,
        array $context = [],
    ): VivaPaymentOrderCancellationException {
        $this->logPaymentEvent($reason === 'paid' ? 'warning' : 'error', 'viva.payment_order_cancel_failed', [
            'order_id' => $order->getKey(),
            'reason' => $reason,
            ...$context,
        ]);

        return new VivaPaymentOrderCancellationException($reason);
    }

    private function confirmTransaction(
        string $transactionId,
        array $transaction,
        bool $skipCompletedOrders = false,
    ): string {
        $orderCode = (string) data_get($transaction, 'orderCode', '');
        $status = (string) data_get($transaction, 'statusId', '');
        $currency = (string) data_get($transaction, 'currencyCode', '');
        $paidCents = $this->amountInCents(data_get($transaction, 'amount'));

        if (! $this->isOrderCode($orderCode)
            || $status !== 'F'
            || $currency !== self::EURO_CURRENCY_CODE
            || $paidCents === null) {
            $this->logPaymentEvent('warning', 'viva.transaction_not_payable', [
                'status' => $status,
                'currency' => $currency,
            ]);

            return 'ignored';
        }

        return DB::transaction(function () use ($transactionId, $orderCode, $paidCents, $skipCompletedOrders): string {
            $order = Order::query()
                ->where('viva_order_code', $orderCode)
                ->lockForUpdate()
                ->first();

            if (! $order || $order->payment_method !== PaymentMethod::Viva) {
                $this->logPaymentEvent('warning', 'viva.transaction_order_not_found');

                return 'ignored';
            }

            if ($skipCompletedOrders && $order->status === OrderStatus::Completed) {
                $this->logPaymentEvent('warning', 'viva.reconciliation_terminal_order_skipped', [
                    'order_id' => $order->getKey(),
                    'status' => $order->status->value,
                ]);

                return 'ignored';
            }

            if ($paidCents !== $this->orderTotalInCents($order)) {
                $this->logPaymentEvent('warning', 'viva.transaction_amount_mismatch', [
                    'order_id' => $order->getKey(),
                    'paid_cents' => $paidCents,
                    'expected_cents' => $this->orderTotalInCents($order),
                ]);

                return 'ignored';
            }

            if ($order->payment_status === 'paid') {
                if (hash_equals((string) $order->viva_transaction_id, $transactionId)) {
                    $this->logPaymentEvent('info', 'viva.webhook_duplicate', [
                        'order_id' => $order->getKey(),
                    ]);

                    return 'duplicate';
                }

                $this->logPaymentEvent('warning', 'viva.paid_order_transaction_mismatch', [
                    'order_id' => $order->getKey(),
                ]);

                return 'ignored';
            }

            $transactionAlreadyUsed = Order::query()
                ->where('viva_transaction_id', $transactionId)
                ->where('id', '!=', $order->getKey())
                ->exists();

            if ($transactionAlreadyUsed) {
                $this->logPaymentEvent('warning', 'viva.transaction_already_used');

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
                    'action_required' => 'refund_or_manual_review',
                ]);

                return 'paid';
            }

            $this->logPaymentEvent('info', 'viva.payment_confirmed', [
                'order_id' => $order->getKey(),
            ]);

            return 'paid';
        });
    }

    private function isReconciliationCandidate(Order $order): bool
    {
        return $order->payment_method === PaymentMethod::Viva
            && $order->payment_status === 'pending'
            && filled($order->viva_order_code)
            && $order->status !== OrderStatus::Completed;
    }

    /** @return list<string> */
    private function retrieveOrderTransactionIds(string $orderCode, int|string $localOrderId): array
    {
        if (! $this->isOrderCode($orderCode)) {
            $this->logPaymentEvent('warning', 'viva.reconciliation_invalid_order_code', [
                'order_id' => $localOrderId,
            ]);

            return [];
        }

        $endpoint = '/api/transactions';
        $response = $this->sendRequest(
            fn (): Response => $this->reconciliationApiClient()->get('/api/transactions/', ['ordercode' => $orderCode]),
            $endpoint,
        );
        $response = $this->jsonObject($this->throwOnFailure($response, $endpoint), $endpoint);

        if (! is_array($response)
            || ($response['Success'] ?? null) !== true
            || ($response['ErrorCode'] ?? null) !== 0
            || ! is_array($response['Transactions'] ?? null)
            || ! array_is_list($response['Transactions'])) {
            throw new VivaResponseException('Viva returned an unsuccessful or invalid transaction search response.');
        }

        $transactionIds = [];
        foreach ($response['Transactions'] as $transaction) {
            $returnedOrderCode = data_get($transaction, 'Order.OrderCode');
            $transactionId = data_get($transaction, 'TransactionId');

            if ((! is_string($returnedOrderCode) && ! is_int($returnedOrderCode)) || ! is_string($transactionId)) {
                $this->logPaymentEvent('warning', 'viva.reconciliation_invalid_search_result', [
                    'order_id' => $localOrderId,
                ]);

                continue;
            }

            $returnedOrderCode = trim((string) $returnedOrderCode);
            $transactionId = strtolower(trim($transactionId));

            if (! hash_equals($orderCode, $returnedOrderCode) || ! Str::isUuid($transactionId)) {
                $this->logPaymentEvent('warning', 'viva.reconciliation_invalid_search_result', [
                    'order_id' => $localOrderId,
                ]);

                continue;
            }

            $transactionIds[] = $transactionId;
        }

        return array_values(array_unique($transactionIds));
    }

    /**
     * Payment logging is best effort: an unavailable log destination must not
     * roll back or mask an otherwise valid payment state transition.
     */
    public function logPaymentEvent(string $level, string $event, array $context = []): void
    {
        unset($context['viva_order_code'], $context['transaction_id']);

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
            ->timeout(self::HTTP_TIMEOUT_SECONDS);
    }

    private function reconciliationApiClient(): PendingRequest
    {
        return Http::baseUrl($this->checkoutBaseUrl())
            ->acceptJson()
            ->withBasicAuth(
                $this->configuredValue('reconciliation_merchant_id'),
                $this->configuredValue('reconciliation_api_key'),
            )
            ->timeout(self::HTTP_TIMEOUT_SECONDS);
    }

    private function accessToken(): string
    {
        $clientId = $this->configuredValue('client_id');
        $cacheKey = 'viva.oauth.'.hash('sha256', ($this->isDemo() ? 'demo:' : 'production:').$clientId);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $store = Cache::getStore();
        if (! $store instanceof LockProvider) {
            throw new VivaConfigurationException('Viva OAuth token caching requires a cache store with atomic lock support.');
        }

        $endpoint = '/connect/token';
        $lockTimeout = self::HTTP_TIMEOUT_SECONDS + 5;

        try {
            return $store->lock($cacheKey.'.refresh-lock', $lockTimeout)->block(
                $lockTimeout,
                function () use ($cacheKey, $clientId, $endpoint): string {
                    $cached = Cache::get($cacheKey);

                    if (is_string($cached) && $cached !== '') {
                        return $cached;
                    }

                    return $this->requestAccessToken($clientId, $cacheKey, $endpoint);
                },
            );
        } catch (LockTimeoutException) {
            $cached = Cache::get($cacheKey);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }

            throw new VivaTransportException('Viva OAuth token refresh timed out.', $endpoint);
        }
    }

    private function requestAccessToken(string $clientId, string $cacheKey, string $endpoint): string
    {
        $response = $this->sendRequest(
            fn (): Response => Http::asForm()
                ->acceptJson()
                ->withBasicAuth($clientId, $this->configuredValue('client_secret'))
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->post($this->accountsBaseUrl().$endpoint, ['grant_type' => 'client_credentials']),
            $endpoint,
        );
        $payload = $this->jsonObject($this->throwOnFailure($response, $endpoint), $endpoint);
        $token = $payload['access_token'] ?? null;
        $expiresIn = $payload['expires_in'] ?? null;

        if (! is_string($token)
            || trim($token) === ''
            || (! is_int($expiresIn) && ! (is_string($expiresIn) && preg_match('/^\d+$/D', $expiresIn) === 1))
            || (int) $expiresIn < 1) {
            throw new VivaResponseException('Viva returned an invalid OAuth response.');
        }

        $token = trim($token);
        $ttl = (int) $expiresIn - 60;

        if ($ttl > 0) {
            Cache::put($cacheKey, $token, $ttl);
        }

        return $token;
    }

    /** @param callable(): Response $request */
    private function sendRequest(callable $request, string $endpoint): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new VivaTransportException('Viva API request could not connect.', $endpoint);
        }
    }

    private function throwOnFailure(
        Response $response,
        string $endpoint,
        bool $forgetAccessTokenOnUnauthorized = false,
    ): Response {
        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();

        if ($forgetAccessTokenOnUnauthorized && $status === 401) {
            $this->forgetAccessToken();
        }

        throw new VivaApiException('Viva API request failed with HTTP status '.$status.'.', $endpoint, $status);
    }

    private function forgetAccessToken(): void
    {
        $clientId = $this->configuredValue('client_id');
        Cache::forget('viva.oauth.'.hash('sha256', ($this->isDemo() ? 'demo:' : 'production:').$clientId));
    }

    /** @return array<string, mixed> */
    private function jsonObject(Response $response, string $endpoint): array
    {
        $payload = $response->json();

        if (! is_array($payload) || array_is_list($payload)) {
            throw new VivaResponseException('Viva returned an invalid JSON response from '.$endpoint.'.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) && ! is_int($value)) {
            throw new VivaResponseException('Viva returned an invalid response field.');
        }

        return trim((string) $value);
    }

    private function configuredValue(string $key): string
    {
        $value = trim((string) config('services.viva.'.$key));

        if ($value === '') {
            throw new VivaConfigurationException('Viva configuration is incomplete.');
        }

        return $value;
    }

    private function isDemo(): bool
    {
        $environment = strtolower((string) config('services.viva.environment', 'demo'));

        if (! in_array($environment, ['demo', 'production', 'live'], true)) {
            throw new VivaConfigurationException('Invalid Viva environment.');
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

    /**
     * Viva quotes amounts in euros with two decimals. Anything else is a
     * malformed answer, not something to round into a match: round($amount *
     * 100) used to turn 4.995 into 500 cents, which satisfied a 5.00 order.
     * Returns null for every input this cannot convert exactly.
     */
    private function amountInCents(mixed $amount): ?int
    {
        $decimal = $this->twoDecimalAmount($amount);

        if ($decimal === null) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $fraction = str_pad($fraction, 2, '0');

        if ((int) $whole > intdiv(PHP_INT_MAX - (int) $fraction, 100)) {
            return null;
        }

        return ((int) $whole * 100) + (int) $fraction;
    }

    /** Normalises a Viva amount to a plain non-negative decimal string, or null. */
    private function twoDecimalAmount(mixed $amount): ?string
    {
        if (is_int($amount)) {
            return $amount >= 0 ? $amount.'.00' : null;
        }

        if (is_float($amount)) {
            if (! is_finite($amount) || $amount < 0) {
                return null;
            }

            // A float carrying more than two decimals is not an amount Viva
            // can have quoted, so it is refused rather than rounded.
            $rendered = sprintf('%.4F', $amount);

            return str_ends_with($rendered, '00') ? substr($rendered, 0, -2) : null;
        }

        if (is_string($amount) && preg_match('/^\d+(?:\.\d{1,2})?$/D', trim($amount)) === 1) {
            return trim($amount);
        }

        return null;
    }

    private function isOrderCode(string $orderCode): bool
    {
        return preg_match('/^\d{16}$/D', $orderCode) === 1;
    }

    /** StateId is a small enumeration, so "3.9" is a malformed answer, not Paid. */
    private function isPaymentOrderState(mixed $state): bool
    {
        return is_int($state) || (is_string($state) && preg_match('/^\d+$/D', $state) === 1);
    }
}
