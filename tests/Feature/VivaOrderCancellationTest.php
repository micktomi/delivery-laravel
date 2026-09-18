<?php

namespace Tests\Feature;

use App\Actions\CancelOrder;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Livewire\OrderBoard;
use App\Models\Order;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cancelling a pending Viva order must first make the payment order unpayable
 * at Viva (Retrieve order, then Cancel payment order — both Basic-authenticated
 * Payment API calls on the same URL, GET then DELETE), and must leave the
 * local order untouched whenever Viva does not confirm that.
 *
 * Data providers run before the application exists, so every canned answer
 * here is a plain [body, status] pair and only becomes an HTTP response
 * inside the test itself.
 */
class VivaOrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private const ORDER_CODE = '7680701046572600';

    private const ORDER_URL = 'https://demo.vivapayments.com/api/orders/'.self::ORDER_CODE;

    private const BASIC_AUTH = 'Basic dGVzdC1tZXJjaGFudC1pZDp0ZXN0LW1lcmNoYW50LWFwaS1rZXk=';

    /** Stands for a call that never gets an answer. */
    private const TIMEOUT = 'timeout';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureViva();
        Http::preventStrayRequests();
    }

    public function test_a_pending_viva_order_is_cancelled_at_viva_before_it_is_cancelled_locally(): void
    {
        $order = $this->pendingVivaOrder();
        $transactionLevelBefore = DB::transactionLevel();
        $transactionLevelDuringCalls = [];

        $this->fakeViva(
            retrieve: function () use (&$transactionLevelDuringCalls) {
                $transactionLevelDuringCalls[] = DB::transactionLevel();

                return Http::response(...self::retrieveSpec(0));
            },
            cancel: function () use (&$transactionLevelDuringCalls, $order) {
                $transactionLevelDuringCalls[] = DB::transactionLevel();
                // Local state is untouched until Viva has answered.
                $this->assertSame(OrderStatus::Nea, $order->fresh()->status);

                return Http::response(...self::cancelSpec());
            },
        );

        app(CancelOrder::class)->execute($order);

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame(self::ORDER_CODE, $order->viva_order_code);

        Http::assertSentInOrder([
            fn (Request $request): bool => $request->method() === 'GET'
                && $request->url() === self::ORDER_URL
                && $request->hasHeader('Authorization', self::BASIC_AUTH),
            fn (Request $request): bool => $request->method() === 'DELETE'
                && $request->url() === self::ORDER_URL
                && $request->hasHeader('Authorization', self::BASIC_AUTH),
        ]);

        // Neither Viva call ran inside a database transaction of its own.
        $this->assertSame([$transactionLevelBefore, $transactionLevelBefore], $transactionLevelDuringCalls);
    }

    #[DataProvider('unpayableStates')]
    public function test_an_order_viva_already_reports_as_unpayable_is_cancelled_locally_without_a_delete(int $stateId): void
    {
        $order = $this->pendingVivaOrder();
        $this->fakeViva(retrieve: self::retrieveSpec($stateId));

        app(CancelOrder::class)->execute($order);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    /** @return array<string, array{0: int}> */
    public static function unpayableStates(): array
    {
        return [
            'already cancelled at Viva (StateId 2)' => [2],
            'expired at Viva (StateId 1)' => [1],
        ];
    }

    public function test_an_order_viva_reports_as_paid_is_not_cancelled(): void
    {
        $order = $this->pendingVivaOrder();
        $this->fakeViva(retrieve: self::retrieveSpec(3));

        $this->assertCancellationRefused($order, 'έχει ήδη πληρωθεί');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    #[DataProvider('vivaFailures')]
    public function test_viva_failures_leave_the_order_unchanged_with_an_operator_message(
        array|string $retrieve,
        array|string|null $cancel,
        string $expectedMessage,
    ): void {
        $order = $this->pendingVivaOrder();
        $this->fakeViva($retrieve, $cancel);

        $this->assertCancellationRefused($order, $expectedMessage);
    }

    /**
     * @return array<string, array{0: array|string, 1: array|string|null, 2: string}>
     */
    public static function vivaFailures(): array
    {
        return [
            'retrieve times out' => [
                self::TIMEOUT, null, 'δεν απάντησε εγκαίρως',
            ],
            'cancel times out' => [
                self::retrieveSpec(0), self::TIMEOUT, 'δεν απάντησε εγκαίρως',
            ],
            'cancel answers with a provider error' => [
                self::retrieveSpec(0), [['Message' => 'Internal Server Error'], 500], 'δεν επιβεβαιώθηκε',
            ],
            'cancel answers 200 without Success' => [
                self::retrieveSpec(0), [['Success' => false, 'ErrorCode' => 1, 'ErrorText' => 'Error Message'], 200], 'δεν επιβεβαιώθηκε',
            ],
            'cancel answers 200 with an empty body' => [
                self::retrieveSpec(0), ['', 200], 'δεν επιβεβαιώθηκε',
            ],
            'cancel answers 404' => [
                self::retrieveSpec(0), [[], 404], 'δεν βρίσκει',
            ],
            'viva rejects the credentials' => [
                [['Message' => 'Unauthorized.'], 401], null, 'απέρριψε τα στοιχεία',
            ],
            'viva does not know the order code' => [
                [[], 404], null, 'δεν βρίσκει',
            ],
            'retrieve answers for a different order code' => [
                [['OrderCode' => 1111111111111111, 'StateId' => 0], 200], null, 'δεν επιβεβαιώθηκε',
            ],
            'retrieve answers without a state' => [
                [['OrderCode' => (int) self::ORDER_CODE], 200], null, 'δεν επιβεβαιώθηκε',
            ],
            'retrieve answers with an unknown state' => [
                [['OrderCode' => (int) self::ORDER_CODE, 'StateId' => 9], 200], null, 'δεν επιβεβαιώθηκε',
            ],
        ];
    }

    public function test_missing_merchant_api_credentials_refuse_the_cancellation_without_calling_viva(): void
    {
        config()->set('services.viva.reconciliation_merchant_id', '');
        config()->set('services.viva.reconciliation_api_key', '');
        $order = $this->pendingVivaOrder();
        Http::fake();

        $this->assertCancellationRefused($order, 'Δεν έχουν ρυθμιστεί');

        Http::assertNothingSent();
    }

    public function test_a_payment_confirmed_during_the_viva_call_is_never_overwritten_by_the_cancellation(): void
    {
        $order = $this->pendingVivaOrder();
        $transactionId = (string) Str::uuid();

        $this->fakeViva(
            retrieve: self::retrieveSpec(0),
            cancel: function () use ($order, $transactionId) {
                // The webhook confirms the payment while Viva is still answering.
                Order::query()->whereKey($order->getKey())->update([
                    'payment_status' => 'paid',
                    'viva_transaction_id' => $transactionId,
                    'paid_at' => now(),
                ]);

                return Http::response(...self::cancelSpec());
            },
        );

        $this->assertCancellationRefused($order, 'επιβεβαιώθηκε εν τω μεταξύ');

        $order->refresh();
        $this->assertSame(OrderStatus::Nea, $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($transactionId, $order->viva_transaction_id);
    }

    /**
     * The payment was already confirmed before the admin clicked cancel. There
     * is nothing left to cancel at Viva, so no call is made, and the paid guard
     * on the locked row is what must refuse it.
     */
    public function test_an_order_already_paid_locally_is_refused_without_calling_viva(): void
    {
        $transactionId = (string) Str::uuid();
        $paidAt = now()->subMinutes(5);
        $order = $this->pendingVivaOrder([
            'payment_status' => 'paid',
            'viva_transaction_id' => $transactionId,
            'paid_at' => $paidAt,
        ]);
        Http::fake();

        $this->assertCancellationRefused($order, 'έχει ήδη πληρωθεί online');

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($transactionId, $order->viva_transaction_id);
        $this->assertSame($paidAt->toDateTimeString(), $order->paid_at?->toDateTimeString());
        Http::assertNothingSent();
    }

    public function test_orders_that_cannot_be_paid_online_are_cancelled_locally_without_calling_viva(): void
    {
        Http::fake();

        $orders = [
            Order::factory()->create(['payment_method' => PaymentMethod::Cash->value]),
            Order::factory()->create(['payment_method' => PaymentMethod::PosCourier->value]),
            // Never reached Smart Checkout: there is nothing at Viva to cancel.
            $this->pendingVivaOrder(['viva_order_code' => null]),
        ];

        foreach ($orders as $order) {
            app(CancelOrder::class)->execute($order);

            $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        }

        Http::assertNothingSent();
    }

    public function test_the_kitchen_board_shows_the_operator_message_when_viva_refuses(): void
    {
        $order = $this->pendingVivaOrder();
        $this->fakeViva(retrieve: self::TIMEOUT);

        Livewire::actingAs(User::factory()->create())
            ->test(OrderBoard::class)
            ->call('cancel', $order->id)
            ->assertHasErrors(['board'])
            ->assertSee('δεν απάντησε εγκαίρως');

        $this->assertSame(OrderStatus::Nea, $order->fresh()->status);
    }

    private function assertCancellationRefused(Order $order, string $expectedMessage): void
    {
        try {
            app(CancelOrder::class)->execute($order);
            $this->fail('Expected the cancellation to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($expectedMessage, $e->validator->errors()->first('status'));
        }

        $order->refresh();
        $this->assertSame(OrderStatus::Nea, $order->status);
        $this->assertSame(self::ORDER_CODE, $order->viva_order_code);
    }

    /**
     * Retrieve (GET) and Cancel (DELETE) share one URL, so a single fake
     * dispatches on the method. Each handler is a [body, status] pair, the
     * TIMEOUT sentinel, a closure, or null when the call must not happen.
     */
    private function fakeViva(array|string|Closure $retrieve, array|string|Closure|null $cancel = null): void
    {
        Http::fake([
            self::ORDER_URL => function (Request $request) use ($retrieve, $cancel) {
                $handler = $request->method() === 'DELETE' ? $cancel : $retrieve;

                if ($handler === null) {
                    $this->fail('Unexpected '.$request->method().' request to Viva.');
                }

                if ($handler instanceof Closure) {
                    return $handler($request);
                }

                if ($handler === self::TIMEOUT) {
                    return Http::failedConnection()($request);
                }

                return Http::response(...$handler);
            },
        ]);
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

    private function pendingVivaOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'payment_method' => PaymentMethod::Viva->value,
            'payment_status' => 'pending',
            'viva_order_code' => self::ORDER_CODE,
            'subtotal' => '5.00',
            'total' => '5.00',
        ], $overrides));
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private static function retrieveSpec(int $stateId): array
    {
        return [['OrderCode' => (int) self::ORDER_CODE, 'StateId' => $stateId], 200];
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private static function cancelSpec(): array
    {
        return [[
            'OrderCode' => (int) self::ORDER_CODE,
            'ErrorCode' => 0,
            'ErrorText' => null,
            'EventId' => 0,
            'Success' => true,
        ], 200];
    }
}
