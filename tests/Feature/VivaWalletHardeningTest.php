<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Exceptions\VivaApiException;
use App\Exceptions\VivaException;
use App\Exceptions\VivaPaymentOrderCancellationException;
use App\Exceptions\VivaResponseException;
use App\Exceptions\VivaTransportException;
use App\Http\Controllers\VivaWalletController;
use App\Models\Order;
use App\Services\VivaWalletService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class VivaWalletHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->configureViva();
        Http::preventStrayRequests();
    }

    public function test_api_failures_do_not_retain_remote_response_bodies_or_previous_exceptions(): void
    {
        Http::fake([
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response([
                'error' => 'invalid_client',
                'secret' => 'never-log-this-provider-body',
            ], 401),
        ]);

        try {
            app(VivaWalletService::class)->createPaymentOrder($this->vivaOrder());
            $this->fail('Expected VivaApiException was not thrown.');
        } catch (VivaApiException $exception) {
            $this->assertSame('/connect/token', $exception->endpoint);
            $this->assertSame(401, $exception->status);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('never-log-this-provider-body', (string) $exception);
            $this->assertStringNotContainsString('test-client-secret', (string) $exception);
        }
    }

    public function test_connection_failures_are_typed_and_use_sanitized_endpoint_labels(): void
    {
        $transactionId = (string) Str::uuid();
        Cache::put($this->tokenCacheKey(), 'cached-token', 3600);
        Http::fake(static fn (): never => throw new ConnectionException('transport-secret-marker'));

        try {
            app(VivaWalletService::class)->retrieveTransaction($transactionId);
            $this->fail('Expected VivaTransportException was not thrown.');
        } catch (VivaTransportException $exception) {
            $this->assertSame('/checkout/v2/transactions/{transactionId}', $exception->endpoint);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString($transactionId, (string) $exception);
            $this->assertStringNotContainsString('transport-secret-marker', (string) $exception);
        }
    }

    public function test_authenticated_401_evicts_the_cached_oauth_token(): void
    {
        $transactionId = (string) Str::uuid();
        Cache::put($this->tokenCacheKey(), 'rejected-token', 3600);
        Http::fake([
            'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId => Http::response([
                'error' => 'invalid_token',
            ], 401),
        ]);

        try {
            app(VivaWalletService::class)->retrieveTransaction($transactionId);
            $this->fail('Expected VivaApiException was not thrown.');
        } catch (VivaApiException $exception) {
            $this->assertSame(401, $exception->status);
            $this->assertFalse(Cache::has($this->tokenCacheKey()));
        }
    }

    public function test_concurrent_oauth_refresh_rechecks_the_cache_inside_an_atomic_lock(): void
    {
        $originalCache = Cache::getFacadeRoot();
        $cache = $this->createMock(CacheRepository::class);
        $store = $this->createMockForIntersectionOfInterfaces([Store::class, LockProvider::class]);
        $lock = $this->createMock(Lock::class);

        $cache->expects($this->exactly(2))
            ->method('get')
            ->with($this->tokenCacheKey())
            ->willReturnOnConsecutiveCalls(null, 'token-from-other-worker');
        $cache->expects($this->never())->method('put');
        $cache->method('getStore')->willReturn($store);
        $store->expects($this->once())
            ->method('lock')
            ->with($this->tokenCacheKey().'.refresh-lock', 15)
            ->willReturn($lock);
        $lock->expects($this->once())
            ->method('block')
            ->with(15, $this->isInstanceOf(\Closure::class))
            ->willReturnCallback(static fn (int $seconds, callable $callback): string => $callback());

        Http::fake([
            'https://demo-api.vivapayments.com/checkout/v2/orders' => Http::response([
                'orderCode' => '7680701046572600',
            ]),
        ]);

        Cache::swap($cache);

        try {
            $this->assertSame(
                '7680701046572600',
                app(VivaWalletService::class)->createPaymentOrder($this->vivaOrder()),
            );
            Http::assertSentCount(1);
            Http::assertSent(fn (Request $request): bool => $request->hasHeader(
                'Authorization',
                'Bearer token-from-other-worker',
            ));
        } finally {
            Cache::swap($originalCache);
        }
    }

    public function test_non_positive_oauth_expiry_is_rejected_and_not_cached(): void
    {
        foreach ([0, -1] as $expiresIn) {
            Cache::flush();
            Http::fake([
                'https://demo-accounts.vivapayments.com/connect/token' => Http::response([
                    'access_token' => 'invalid-token',
                    'expires_in' => $expiresIn,
                ]),
            ]);

            try {
                app(VivaWalletService::class)->createPaymentOrder($this->vivaOrder());
                $this->fail('Expected VivaResponseException was not thrown.');
            } catch (VivaResponseException $exception) {
                $this->assertSame('Viva returned an invalid OAuth response.', $exception->getMessage());
                $this->assertFalse(Cache::has($this->tokenCacheKey()));
            }
        }
    }

    public function test_webhook_parser_rejects_ambiguous_types_without_http_requests(): void
    {
        $order = $this->vivaOrder(['viva_order_code' => '7680701046572600']);
        $transactionId = (string) Str::uuid();
        $service = app(VivaWalletService::class);
        $payloads = [
            ['EventTypeId' => '1796junk', 'EventData' => ['TransactionId' => $transactionId, 'OrderCode' => $order->viva_order_code]],
            ['EventTypeId' => 1796.5, 'EventData' => ['TransactionId' => $transactionId, 'OrderCode' => $order->viva_order_code]],
            ['EventTypeId' => 1796, 'EventData' => ['TransactionId' => [$transactionId], 'OrderCode' => $order->viva_order_code]],
            ['EventTypeId' => 1796, 'EventData' => ['TransactionId' => $transactionId, 'OrderCode' => '1']],
        ];

        foreach ($payloads as $payload) {
            $this->assertSame('ignored', $service->processWebhook($payload));
        }

        Http::assertNothingSent();
    }

    public function test_non_sixteen_digit_order_codes_are_rejected_from_provider_and_public_input(): void
    {
        Http::fake([
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://demo-api.vivapayments.com/checkout/v2/orders' => Http::response([
                'orderCode' => '1',
            ]),
        ]);

        try {
            app(VivaWalletService::class)->createPaymentOrder($this->vivaOrder());
            $this->fail('Expected VivaResponseException was not thrown.');
        } catch (VivaResponseException $exception) {
            $this->assertSame('Viva returned an invalid payment order code.', $exception->getMessage());
        }

        $this->expectException(VivaException::class);
        $this->expectExceptionMessage('Invalid Viva payment order code.');

        app(VivaWalletService::class)->checkoutUrl('1');
    }

    public function test_payment_logs_remove_provider_order_and_transaction_identifiers(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('log')
            ->once()
            ->with('warning', 'viva.test', Mockery::on(fn (array $context): bool => $context === [
                'order_id' => 42,
            ]));
        Log::shouldReceive('channel')->once()->with('payments')->andReturn($logger);

        app(VivaWalletService::class)->logPaymentEvent('warning', 'viva.test', [
            'order_id' => 42,
            'viva_order_code' => '7680701046572600',
            'transaction_id' => (string) Str::uuid(),
        ]);
    }

    public function test_cancellation_transport_failures_do_not_retain_the_raw_exception(): void
    {
        $order = $this->vivaOrder(['viva_order_code' => '7680701046572600']);
        Http::fake(static fn (): never => throw new ConnectionException('merchant-transport-secret'));

        try {
            app(VivaWalletService::class)->cancelPaymentOrder($order);
            $this->fail('Expected VivaPaymentOrderCancellationException was not thrown.');
        } catch (VivaPaymentOrderCancellationException $exception) {
            $this->assertSame('unreachable', $exception->reason);
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('merchant-transport-secret', (string) $exception);
        }
    }

    /**
     * The lease must outlive the worst case it protects, so it is derived from
     * the HTTP budget rather than pinned to a number: raising the request
     * timeout has to break this test, not silently shorten the margin.
     */
    public function test_payment_start_lock_covers_the_full_oauth_and_order_request_budget(): void
    {
        $lockSeconds = (new \ReflectionClass(VivaWalletController::class))
            ->getReflectionConstant('PAYMENT_START_LOCK_SECONDS')?->getValue();
        $timeout = (new \ReflectionClass(VivaWalletService::class))
            ->getReflectionConstant('HTTP_TIMEOUT_SECONDS')?->getValue();

        $this->assertIsInt($lockSeconds);
        $this->assertIsInt($timeout);

        // Waiting out another worker's OAuth refresh lock, then the token
        // request, then the create-order request.
        $oauthLockWait = $timeout + 5;
        $worstCase = $oauthLockWait + (2 * $timeout);

        $this->assertGreaterThan(
            $worstCase,
            $lockSeconds,
            sprintf(
                'The %ds lock lease must outlive the %ds worst case: a %ds OAuth lock wait, a %ds token request and a %ds order request.',
                $lockSeconds,
                $worstCase,
                $oauthLockWait,
                $timeout,
                $timeout,
            ),
        );
    }

    /**
     * Amounts arrive from Viva as JSON numbers and used to go through
     * round($amount * 100), so 4.995 became 500 cents and satisfied a 5.00
     * order. Viva sends two decimals, so anything else is a malformed answer
     * and must be refused rather than quietly rounded into a match.
     */
    public function test_a_sub_cent_amount_is_refused_instead_of_being_rounded_into_a_match(): void
    {
        $order = $this->vivaOrder(['viva_order_code' => '7680701046572600']);
        $transactionId = (string) Str::uuid();

        foreach ([4.995, 5.001, '4.995', '5e0', '0x5'] as $amount) {
            Cache::put($this->tokenCacheKey(), 'cached-token', 3600);
            Http::fake([
                'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId => Http::response([
                    'amount' => $amount,
                    'orderCode' => '7680701046572600',
                    'statusId' => 'F',
                    'currencyCode' => '978',
                ]),
            ]);

            $this->assertSame('ignored', app(VivaWalletService::class)->processWebhook([
                'EventTypeId' => 1796,
                'EventData' => ['TransactionId' => $transactionId, 'OrderCode' => '7680701046572600'],
            ]), 'amount '.var_export($amount, true));

            $order->refresh();
            $this->assertSame('pending', $order->payment_status, 'amount '.var_export($amount, true));
            $this->assertNull($order->paid_at);
        }
    }

    public function test_the_amounts_viva_actually_sends_are_still_accepted(): void
    {
        foreach ([5.00, 5, '5.00', '5'] as $amount) {
            $order = $this->vivaOrder(['viva_order_code' => (string) (7680701046572600 + count(Order::all()))]);
            $transactionId = (string) Str::uuid();
            Cache::put($this->tokenCacheKey(), 'cached-token', 3600);
            Http::fake([
                'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId => Http::response([
                    'amount' => $amount,
                    'orderCode' => $order->viva_order_code,
                    'statusId' => 'F',
                    'currencyCode' => '978',
                ]),
            ]);

            $this->assertSame('paid', app(VivaWalletService::class)->processWebhook([
                'EventTypeId' => 1796,
                'EventData' => ['TransactionId' => $transactionId, 'OrderCode' => $order->viva_order_code],
            ]), 'amount '.var_export($amount, true));
        }
    }

    private function configureViva(): void
    {
        config()->set('services.viva', [
            'enabled' => true,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'source_code' => 'test-source',
            'environment' => 'demo',
            'webhook_verification_key' => 'test-verification-key',
            'reconciliation_merchant_id' => 'test-merchant-id',
            'reconciliation_api_key' => 'test-merchant-api-key',
        ]);
    }

    private function vivaOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'payment_method' => PaymentMethod::Viva->value,
            'payment_status' => 'pending',
            'viva_order_code' => null,
            'subtotal' => '5.00',
            'total' => '5.00',
        ], $overrides));
    }

    private function tokenCacheKey(): string
    {
        return 'viva.oauth.'.hash('sha256', 'demo:test-client-id');
    }
}
