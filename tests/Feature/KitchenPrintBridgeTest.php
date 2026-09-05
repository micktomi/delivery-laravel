<?php

namespace Tests\Feature;

use App\Actions\CreateKitchenPrintJob;
use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class KitchenPrintBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        config(['printing.token' => 'test-token']);
        Http::preventStrayRequests();
    }

    private function acceptOrder(): PrintJob
    {
        $order = Order::factory()->create(['customer_name' => 'Μαρία', 'notes' => 'Χωρίς καλαμάκι']);
        $order->items()->create([
            'product_name' => 'Καφές', 'base_price' => '2.50', 'unit_price' => '2.50',
            'quantity' => 2, 'line_total' => '5.00', 'notes' => 'Λίγος πάγος',
            'selected_options' => [['group' => 'Ζάχαρη', 'value' => 'Σκέτος', 'price_delta' => 0]],
        ]);
        $this->assertDatabaseCount('print_jobs', 0);
        app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);

        return PrintJob::sole();
    }

    public function test_acceptance_snapshots_only_kitchen_data_and_never_contacts_worker(): void
    {
        $job = $this->acceptOrder();
        $this->assertSame('pending', $job->status);
        $this->assertSame('kitchen', $job->payload['type']);
        $this->assertSame($job->id, $job->payload['print_job_id']);
        $this->assertSame(['name' => 'Μαρία'], $job->payload['customer']);
        $this->assertSame([[
            'name' => 'Καφές', 'quantity' => 2,
            'options' => [['group' => 'Ζάχαρη', 'value' => 'Σκέτος']], 'notes' => 'Λίγος πάγος',
        ]], $job->payload['items']);
        $this->assertArrayNotHasKey('total', $job->payload);
        $this->assertArrayNotHasKey('address', $job->payload);
        Http::assertNothingSent();
    }

    public function test_stale_acceptance_and_later_transitions_do_not_duplicate_job(): void
    {
        $job = $this->acceptOrder();
        $order = Order::findOrFail($job->order_id);
        try {
            app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);
            $this->fail('Stale acceptance must fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('print_jobs', 1);
        }
        app(TransitionOrderStatus::class)->execute($order, OrderStatus::Preparing);
        $this->assertDatabaseCount('print_jobs', 1);
        $this->assertSame($job->payload, $job->fresh()->payload);
    }

    public function test_unpaid_online_order_cannot_emit_job(): void
    {
        $order = Order::factory()->create(['payment_method' => PaymentMethod::Viva, 'payment_status' => 'pending']);
        try {
            app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);
            $this->fail('Unpaid order must fail.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('print_jobs', 0);
        }
        $order->update(['payment_status' => 'paid']);
        app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);
        $this->assertDatabaseCount('print_jobs', 1);
    }

    public function test_rollback_removes_both_acceptance_and_job(): void
    {
        $order = Order::factory()->create();
        DB::beginTransaction();
        app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);
        DB::rollBack();
        $this->assertSame(OrderStatus::Nea, $order->fresh()->status);
        $this->assertDatabaseCount('print_jobs', 0);
    }

    public function test_job_insert_failure_rolls_back_acceptance(): void
    {
        $order = Order::factory()->create();
        $this->mock(CreateKitchenPrintJob::class)
            ->shouldReceive('execute')->once()->andThrow(new \RuntimeException('storage unavailable'));
        try {
            app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);
            $this->fail('Expected storage failure.');
        } catch (\RuntimeException) {
            $this->assertSame(OrderStatus::Nea, $order->fresh()->status);
            $this->assertDatabaseCount('print_jobs', 0);
        }
    }

    private function poll(): TestResponse
    {
        return $this->withToken('test-token')->postJson('/api/printing/next');
    }

    private function report(PrintJob $job, string $outcome, array $data): TestResponse
    {
        return $this->withToken('test-token')->postJson("/api/printing/{$job->id}/{$outcome}", $data);
    }

    public function test_claim_is_hidden_for_60_seconds_then_reissued_with_identical_snapshot(): void
    {
        $job = $this->acceptOrder();
        $this->poll()->assertOk()->assertExactJson(['attempt' => 1, 'payload' => $job->payload]);
        $started = $job->fresh()->last_attempt_at;
        Order::findOrFail($job->order_id)->update(['customer_name' => 'Changed']);
        $this->travel(59)->seconds();
        $this->poll()->assertNoContent();
        $this->assertSame('pending', $job->fresh()->status);
        $this->assertSame(1, $job->fresh()->attempts);
        $this->assertTrue($started->equalTo($job->fresh()->last_attempt_at));
        $this->travel(1)->seconds();
        $this->poll()->assertOk()->assertExactJson(['attempt' => 2, 'payload' => $job->payload]);
        $this->report($job, 'accepted', ['attempt' => 1])->assertConflict();
        $this->report($job, 'failed', ['attempt' => 1, 'error' => 'invalid_payload'])->assertConflict();
        $this->report($job, 'accepted', ['attempt' => 2])->assertOk();
        Http::assertNothingSent();
    }

    public function test_expired_lease_cannot_be_resolved_before_reclaim(): void
    {
        $job = $this->acceptOrder();
        $this->poll()->assertOk();
        $this->travel(60)->seconds();
        $this->report($job, 'accepted', ['attempt' => 1])->assertConflict();
        $this->report($job, 'failed', ['attempt' => 1, 'error' => 'invalid_payload'])->assertConflict();
        $this->assertSame('pending', $job->fresh()->status);
        $this->poll()->assertJsonPath('attempt', 2);
    }

    public function test_live_claim_does_not_block_the_next_order(): void
    {
        $first = $this->acceptOrder();
        $this->poll()->assertJsonPath('payload.print_job_id', $first->id);
        $order = Order::factory()->create();
        app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);
        $second = PrintJob::where('order_id', $order->id)->sole();
        $this->poll()->assertJsonPath('payload.print_job_id', $second->id);
        $this->poll()->assertNoContent();
        $this->assertSame(1, $first->fresh()->attempts);
        $this->assertSame(1, $second->fresh()->attempts);
    }

    public function test_three_second_polling_is_allowed_and_printing_limit_is_enforced(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->poll()->assertNoContent();
            $this->travel(3)->seconds();
        }
        $this->travel(61)->seconds();
        for ($i = 0; $i < 120; $i++) {
            $this->poll()->assertNoContent();
        }
        $this->poll()->assertStatus(429);
    }

    public function test_printing_rate_limit_does_not_change_other_route_limits(): void
    {
        Route::post('/test-limited', fn () => response()->noContent())
            ->middleware('throttle:2,1');
        $this->postJson('/test-limited')->assertNoContent();
        $this->postJson('/test-limited')->assertNoContent();
        $this->postJson('/test-limited')->assertStatus(429);
        $this->poll()->assertNoContent();
        $this->postJson('/test-limited')->assertStatus(429);
    }

    public function test_ack_is_idempotent_and_sent_jobs_are_never_requeued(): void
    {
        $job = $this->acceptOrder();
        $this->poll()->assertOk();
        $this->report($job, 'accepted', ['attempt' => 1])->assertOk()
            ->assertExactJson(['print_job_id' => $job->id, 'status' => 'sent']);
        $sentAt = $job->fresh()->sent_at;
        $this->assertNotNull($sentAt);
        $this->travel(61)->seconds();
        $this->report($job, 'accepted', ['attempt' => 1])->assertOk();
        $this->assertTrue($sentAt->equalTo($job->fresh()->sent_at));
        $this->report($job, 'failed', ['attempt' => 1, 'error' => 'storage_unavailable'])->assertConflict();
        $this->artisan('printing:retry-failed')->assertSuccessful();
        $this->poll()->assertNoContent();
    }

    public function test_failure_requires_explicit_retry_and_reuses_snapshot_with_a_new_attempt(): void
    {
        $job = $this->acceptOrder();
        $this->poll()->assertOk();
        $failure = ['attempt' => 1, 'error' => 'storage_unavailable'];
        $this->report($job, 'failed', $failure)->assertOk();
        $this->report($job, 'failed', $failure)->assertOk();
        $this->assertSame('storage_unavailable', $job->fresh()->last_error);
        $this->assertNull($job->fresh()->sent_at);
        $this->poll()->assertNoContent();
        $this->report($job, 'accepted', ['attempt' => 1])->assertConflict();
        Order::findOrFail($job->order_id)->update(['customer_name' => 'Changed']);
        $this->artisan('printing:retry-failed', ['id' => $job->id])->assertSuccessful();
        $this->report($job, 'failed', $failure)->assertConflict();
        $this->report($job, 'accepted', ['attempt' => 1])->assertConflict();
        $this->poll()->assertOk()->assertExactJson(['attempt' => 2, 'payload' => $job->payload]);
        $this->report($job, 'failed', $failure)->assertConflict();
        $this->report($job, 'accepted', ['attempt' => 1])->assertConflict();
        $this->report($job, 'accepted', ['attempt' => 2])->assertOk();
        $this->assertSame(2, $job->fresh()->attempts);
        $this->assertNull($job->fresh()->last_error);
        $this->assertSame($job->payload, $job->fresh()->payload);
        $this->assertDatabaseCount('print_jobs', 1);
    }

    public function test_poll_returns_oldest_pending_job_then_advances_after_resolution(): void
    {
        $job = $this->acceptOrder();
        $this->travel(3)->seconds();
        $order = Order::factory()->create();
        app(TransitionOrderStatus::class)->execute($order, OrderStatus::Nea);
        $second = PrintJob::where('order_id', $order->id)->sole();
        $this->poll()->assertJsonPath('payload.print_job_id', $job->id);
        $this->report($job, 'failed', ['attempt' => 1, 'error' => 'invalid_payload'])->assertOk();
        $this->poll()->assertJsonPath('payload.print_job_id', $second->id);
        $this->report($second, 'accepted', ['attempt' => 1])->assertOk();
        $this->poll()->assertNoContent();
    }

    public function test_every_endpoint_requires_the_configured_bearer_secret(): void
    {
        $job = $this->acceptOrder();
        $paths = ['/api/printing/next', "/api/printing/{$job->id}/accepted", "/api/printing/{$job->id}/failed"];
        foreach ($paths as $path) {
            $this->postJson($path, ['attempt' => 1])->assertUnauthorized();
            $this->withToken('wrong')->postJson($path, ['attempt' => 1])->assertUnauthorized();
            $this->flushHeaders();
        }
        config(['printing.token' => '']);
        $this->poll()->assertUnauthorized();
        config(['printing.token' => null]);
        $this->poll()->assertUnauthorized();
        $this->assertSame(0, $job->fresh()->attempts);
        $this->assertSame('pending', $job->fresh()->status);
    }

    public function test_callbacks_validate_input_and_require_a_fetched_job(): void
    {
        $job = $this->acceptOrder();
        $this->report($job, 'accepted', ['attempt' => 1])->assertConflict();
        $this->poll()->assertOk();
        $this->report($job, 'accepted', [])->assertUnprocessable();
        $this->report($job, 'accepted', ['attempt' => 0])->assertUnprocessable();
        $this->report($job, 'failed', ['attempt' => 1, 'error' => 'PII or printer offline'])->assertUnprocessable();
        $this->report($job, 'failed', ['attempt' => 1])->assertUnprocessable();
        $this->report($job, 'accepted', ['attempt' => 1, 'error' => 'invalid_payload'])->assertUnprocessable();
        $this->withToken('test-token')->postJson('/api/printing/00000000-0000-4000-8000-000000000000/accepted', ['attempt' => 1])->assertNotFound();
        $this->assertSame('pending', $job->fresh()->status);
    }

    public function test_poll_is_not_cacheable(): void
    {
        $this->poll()->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');

    }

    public function test_retention_removes_snapshot_but_keeps_deduplication_tombstone(): void
    {
        $job = $this->acceptOrder();
        Order::findOrFail($job->order_id)->update(['status' => OrderStatus::Completed, 'placed_at' => now()->subDays(60)]);
        config(['retention.orders.anonymization_days' => 30]);
        $this->artisan('orders:anonymize-personal-data')->assertSuccessful();
        $this->assertNull($job->fresh()->payload);
        $this->assertSame('payload_expired', $job->fresh()->last_error);
        $this->artisan('printing:retry-failed')->assertSuccessful();
        $this->poll()->assertNoContent();
        Http::assertNothingSent();
    }
}
