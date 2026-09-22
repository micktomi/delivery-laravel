<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Livewire\OrderBoard;
use App\Models\Order;
use App\Models\User;
use App\Services\VivaWalletService;
use App\Support\VivaPaymentHealth;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class VivaPaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    /** Payment order StateId values of the Retrieve order API. */
    private const STATE_PENDING = 0;

    private const STATE_EXPIRED = 1;

    private const STATE_CANCELED = 2;

    private const STATE_PAID = 3;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-28 12:00:00');
        $this->configureViva();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_successful_reconciliation_confirms_a_recent_pending_payment(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $this->fakeReconciliation($order, $transactionId);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($transactionId, $order->viva_transaction_id);
        $this->assertNotNull($order->paid_at);
        // No callback or webhook is invoked: the stored order code is enough.
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://demo.vivapayments.com/api/transactions/?ordercode='.$order->viva_order_code
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-merchant-id:test-merchant-api-key')));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId
            && $request->hasHeader('Authorization', 'Bearer test-access-token'));
        Http::assertSentCount(3);
    }

    public function test_pending_payment_without_a_viva_transaction_remains_pending(): void
    {
        $order = $this->vivaOrder();
        $this->fakeReconciliation($order, null);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->viva_transaction_id);
    }

    public static function unpayablePaymentOrderStates(): array
    {
        return [
            'expired at Viva' => [self::STATE_EXPIRED],
            'cancelled at Viva' => [self::STATE_CANCELED],
        ];
    }

    /**
     * An abandoned checkout used to stay pending for ever and be re-polled
     * every five minutes until the end of time.
     */
    #[DataProvider('unpayablePaymentOrderStates')]
    public function test_a_payment_order_that_can_no_longer_be_paid_leaves_the_candidate_set(int $state): void
    {
        $order = $this->vivaOrder();
        $this->fakeReconciliation($order, null, orderState: $state);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('expired', $order->payment_status);
        $this->assertNull($order->viva_transaction_id);
        $this->assertNull($order->paid_at);
        // The Viva order code is kept: it is the only audit trail back to Viva.
        $this->assertNotNull($order->viva_order_code);

        // The next scheduled run must not look at this order again at all.
        Http::fake();
        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_a_payment_order_still_pending_at_viva_stays_a_candidate(): void
    {
        $order = $this->vivaOrder();
        $this->fakeReconciliation($order, null, orderState: self::STATE_PENDING);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    /**
     * Viva says paid but the search has not caught up. Expiring the order here
     * would hide money that is already in the account.
     */
    public function test_a_paid_payment_order_is_never_expired_by_reconciliation(): void
    {
        $order = $this->vivaOrder();
        $this->fakeReconciliation($order, null, orderState: self::STATE_PAID);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_expiry_never_touches_the_local_order_status(): void
    {
        $order = $this->vivaOrder(['status' => OrderStatus::Cancelled->value]);
        $this->fakeReconciliation($order, null, orderState: self::STATE_EXPIRED);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $order->refresh();
        $this->assertSame('expired', $order->payment_status);
        $this->assertSame(OrderStatus::Cancelled, $order->status);
    }

    public function test_an_unreachable_order_state_lookup_leaves_the_order_pending_for_a_retry(): void
    {
        $order = $this->vivaOrder();
        Http::fake([
            'https://demo.vivapayments.com/api/transactions/?ordercode='.$order->viva_order_code => Http::response(
                $this->searchResponse($order, null),
            ),
            'https://demo.vivapayments.com/api/orders/'.$order->viva_order_code => Http::response(['error' => 'boom'], 500),
        ]);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_an_expired_payment_is_not_counted_as_a_stale_pending_payment(): void
    {
        $this->vivaOrder([
            'payment_status' => 'expired',
            'created_at' => now()->subHours(3),
            'placed_at' => now()->subHours(3),
        ]);

        $counts = app(VivaPaymentHealth::class)->counts();

        $this->assertSame(0, $counts['pending']);
        $this->assertSame(0, $counts['inconsistent']);
    }

    public function test_amount_mismatch_is_not_paid_and_is_logged_for_review(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $this->fakeReconciliation($order, $transactionId, ['amount' => 6.00]);

        $this->expectPaymentLog('viva.transaction_amount_mismatch', function (array $context) use ($order): bool {
            return $context['order_id'] === $order->id
                && ! array_key_exists('transaction_id', $context)
                && ! array_key_exists('viva_order_code', $context)
                && $context['paid_cents'] === 600
                && $context['expected_cents'] === 500;
        });

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_currency_mismatch_is_not_paid_and_is_logged_for_review(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $this->fakeReconciliation($order, $transactionId, ['currencyCode' => '840']);

        $this->expectPaymentLog('viva.transaction_not_payable', function (array $context): bool {
            return ! array_key_exists('transaction_id', $context)
                && ! array_key_exists('viva_order_code', $context)
                && $context['currency'] === '840';
        });

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_duplicate_reconciliation_is_idempotent(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $this->fakeReconciliation($order, $transactionId);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);
        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, Order::query()->where('viva_transaction_id', $transactionId)->count());
        Http::assertSentCount(3);
    }

    public function test_webhook_and_reconciliation_race_leaves_one_paid_transaction(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $webhookProcessed = false;
        $transaction = $this->verifiedTransaction($order);

        Http::fake([
            'https://demo.vivapayments.com/api/transactions/?ordercode='.$order->viva_order_code => Http::response(
                $this->searchResponse($order, $transactionId),
            ),
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]),
            'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId => function (Request $request) use (&$webhookProcessed, $order, $transactionId, $transaction) {
                if (! $webhookProcessed) {
                    $webhookProcessed = true;
                    app(VivaWalletService::class)->processWebhook($this->webhookPayload($transactionId, $order->viva_order_code));
                }

                return Http::response($transaction);
            },
        ]);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertTrue($webhookProcessed);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame($transactionId, $order->fresh()->viva_transaction_id);
        $this->assertSame(1, Order::query()->where('viva_transaction_id', $transactionId)->count());
    }

    public function test_already_paid_order_is_not_reconciled_again(): void
    {
        $order = $this->vivaOrder([
            'payment_status' => 'paid',
            'viva_transaction_id' => (string) Str::uuid(),
            'paid_at' => now(),
        ]);
        Http::fake();

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('paid', $order->fresh()->payment_status);
        Http::assertNothingSent();
    }

    public function test_paid_cancelled_order_is_recorded_for_manual_refund_without_reopening(): void
    {
        $order = $this->vivaOrder(['status' => OrderStatus::Cancelled->value]);
        $transactionId = (string) Str::uuid();
        $this->fakeReconciliation($order, $transactionId);
        $this->expectPaymentLog('viva.payment_received_after_cancellation', fn (array $context): bool => $context['order_id'] === $order->id
            && $context['action_required'] === 'refund_or_manual_review', 'critical');

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame($transactionId, $order->fresh()->viva_transaction_id);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertDatabaseCount('print_jobs', 0);
        Livewire::actingAs(User::factory()->create())
            ->test(OrderBoard::class)->assertDontSee($order->customer_name);
    }

    public function test_reconciliation_is_scheduled_every_five_minutes_without_overlap(): void
    {
        $event = collect(app('Illuminate\\Console\\Scheduling\\Schedule')->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'viva:reconcile-pending-payments'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_viva_webhook_has_no_fixed_throttle_middleware(): void
    {
        $webhook = Route::getRoutes()->getByName('viva.webhook');

        $this->assertNotNull($webhook);
        $this->assertNotContains('throttle:60,1', $webhook->gatherMiddleware());
    }

    public static function rejectedVerifiedTransactions(): array
    {
        return [
            'wrong order' => [['orderCode' => 9999999999999999]],
            'failed' => [['statusId' => 'E']],
            'in progress' => [['statusId' => 'A']],
            'cancelled payment' => [['statusId' => 'X']],
        ];
    }

    #[DataProvider('rejectedVerifiedTransactions')]
    public function test_search_success_never_overrides_retrieve_transaction_verification(array $overrides): void
    {
        $order = $this->vivaOrder();
        $this->fakeReconciliation($order, (string) Str::uuid(), $overrides);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->viva_transaction_id);
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_reconciliation_recovers_a_payment_after_a_week_long_outage(): void
    {
        $order = $this->vivaOrder(['created_at' => now()->subWeek(), 'placed_at' => now()->subWeek()]);
        $transactionId = (string) Str::uuid();
        $this->fakeReconciliation($order, $transactionId);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame($transactionId, $order->fresh()->viva_transaction_id);
    }

    public function test_mismatched_lookup_order_is_not_verified_or_paid(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $search = $this->searchResponse($order, $transactionId);
        $search['Transactions'][0]['Order']['OrderCode']++;
        $this->fakeReconciliation($order, $transactionId, [], $search);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('pending', $order->fresh()->payment_status);
        // The search result was unusable, so the order state is asked for as
        // well; it answers Pending, which keeps the order a candidate.
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/checkout/v2/transactions/'));
    }

    public function test_unsuccessful_search_envelope_is_a_retryable_failure(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $search = $this->searchResponse($order, $transactionId);
        $search['Success'] = false;
        $search['ErrorCode'] = 1;
        $this->fakeReconciliation($order, $transactionId, [], $search);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(1);

        $this->assertSame('pending', $order->fresh()->payment_status);
        Http::assertSentCount(1);
    }

    public function test_failed_attempt_does_not_hide_a_later_successful_transaction(): void
    {
        $order = $this->vivaOrder();
        $failedId = (string) Str::uuid();
        $paidId = (string) Str::uuid();
        $search = $this->searchResponse($order, $failedId);
        $search['Transactions'][] = $this->searchResponse($order, $paidId)['Transactions'][0];
        Http::fake([
            'https://demo.vivapayments.com/api/transactions/?ordercode='.$order->viva_order_code => Http::response($search),
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600]),
            'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$failedId => Http::response($this->verifiedTransaction($order, ['statusId' => 'E'])),
            'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$paidId => Http::response($this->verifiedTransaction($order)),
        ]);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame($paidId, $order->fresh()->viva_transaction_id);
    }

    public function test_duplicate_webhook_after_reconciliation_does_not_change_payment(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        $this->fakeReconciliation($order, $transactionId);
        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);
        $paidAt = $order->fresh()->paid_at->toISOString();

        $this->assertSame('duplicate', app(VivaWalletService::class)->processWebhook(
            $this->webhookPayload($transactionId, $order->viva_order_code),
        ));
        $this->assertSame($paidAt, $order->fresh()->paid_at->toISOString());
        $this->assertSame(1, Order::where('viva_transaction_id', $transactionId)->count());
    }

    public function test_reconciliation_preserves_cancellation_that_occurs_during_verification(): void
    {
        $order = $this->vivaOrder();
        $transactionId = (string) Str::uuid();
        Http::fake([
            'https://demo.vivapayments.com/api/transactions/?ordercode='.$order->viva_order_code => Http::response($this->searchResponse($order, $transactionId)),
            'https://demo-accounts.vivapayments.com/connect/token' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600]),
            'https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId => function () use ($order) {
                $order->update(['status' => OrderStatus::Cancelled]);

                return Http::response($this->verifiedTransaction($order));
            },
        ]);

        $this->artisan('viva:reconcile-pending-payments')->assertExitCode(0);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('print_jobs', 0);
    }

    private function configureViva(): void
    {
        config()->set('services.viva', [
            'enabled' => true,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'source_code' => 'test-source',
            'environment' => 'demo',
            'reconciliation_merchant_id' => 'test-merchant-id',
            'reconciliation_api_key' => 'test-merchant-api-key',
        ]);
    }

    private function vivaOrder(array $overrides = []): Order
    {
        static $orderCode = 7680701046572600;

        return Order::factory()->create(array_merge([
            'payment_method' => PaymentMethod::Viva->value,
            'payment_status' => 'pending',
            'viva_order_code' => (string) $orderCode++,
            'subtotal' => '5.00',
            'total' => '5.00',
            'created_at' => now()->subMinutes(10),
            'placed_at' => now()->subMinutes(10),
        ], $overrides));
    }

    private function fakeReconciliation(
        Order $order,
        ?string $transactionId,
        array $overrides = [],
        ?array $search = null,
        int $orderState = self::STATE_PENDING,
    ): void {
        $responses = [
            'https://demo.vivapayments.com/api/transactions/?ordercode='.$order->viva_order_code => Http::response(
                $search ?? $this->searchResponse($order, $transactionId),
            ),
            // Asked only when the search produced no payable transaction, to
            // find out whether the customer can still pay this order at all.
            'https://demo.vivapayments.com/api/orders/'.$order->viva_order_code => Http::response([
                'OrderCode' => (int) $order->viva_order_code,
                'StateId' => $orderState,
            ]),
        ];

        if ($transactionId !== null) {
            $responses['https://demo-accounts.vivapayments.com/connect/token'] = Http::response([
                'access_token' => 'test-access-token',
                'expires_in' => 3600,
            ]);
            $responses['https://demo-api.vivapayments.com/checkout/v2/transactions/'.$transactionId] = Http::response(
                $this->verifiedTransaction($order, $overrides),
            );
        }

        Http::fake($responses);
    }

    private function verifiedTransaction(Order $order, array $overrides = []): array
    {
        return array_merge($this->fixture('retrieve-transaction'), [
            'orderCode' => (int) $order->viva_order_code,
        ], $overrides);
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/viva/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function searchResponse(Order $order, ?string $transactionId): array
    {
        $response = $this->fixture('transaction-search');
        if ($transactionId === null) {
            $response['Transactions'] = [];
        } else {
            $response['Transactions'][0]['TransactionId'] = $transactionId;
            $response['Transactions'][0]['Order']['OrderCode'] = (int) $order->viva_order_code;
        }

        return $response;
    }

    private function webhookPayload(string $transactionId, string $orderCode): array
    {
        return [
            'EventTypeId' => 1796,
            'EventData' => [
                'TransactionId' => $transactionId,
                'OrderCode' => $orderCode,
            ],
        ];
    }

    private function expectPaymentLog(string $event, callable $contextMatches, string $level = 'warning'): void
    {
        $paymentLogger = Mockery::mock(LoggerInterface::class);
        $paymentLogger->shouldReceive('log')
            ->once()
            ->with($level, $event, Mockery::on($contextMatches));

        Log::shouldReceive('channel')->once()->with('payments')->andReturn($paymentLogger);
    }
}
