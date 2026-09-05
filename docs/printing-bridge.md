# Kitchen printing bridge v1 — pull transport

## Laravel flow

`TransitionOrderStatus::execute`: on ΝΕΑ → ΕΤΟΙΜΑΖΕΤΑΙ, after payment and stale-status checks, create one `print_jobs` row in the same database transaction as acceptance. An insert failure rolls back acceptance. Existing kitchen and admin actions share this trigger. Checkout and payment callbacks do not emit receipts. No historical backfill or automatic reprint is provided.

The unique `order_id` constraint and locked order prevent duplicate kitchen jobs. The UUID is generated once and persisted. The payload is a snapshot of ordered order-item records, never the live product catalogue. Every poll/retry returns the same JSON values and ID; key ordering/whitespace is not part of the contract. Editing an order does not change an existing receipt. Cancellation after acceptance does not retract that acceptance receipt or send a cancellation ticket. The stable type is `kitchen`. `delivery` is reserved for a future contract extension; no delivery jobs or workflow are implemented.

The existing kitchen board supplies the scope: customer name for handover, items/options and notes. No address, phone, email, payment details, totals, option prices or public tracking token are sent. Delivery logistics remain on the driver screen.

## Authentication and network

The shop worker opens outbound HTTPS requests to Laravel. Laravel never calls a worker endpoint. No inbound shop port, private tunnel, broker or scheduler dispatch is needed. Configure the same high-entropy `PRINT_WORKER_TOKEN` in Laravel and the worker; use `Authorization: Bearer <token>` on all requests, with `Accept: application/json` and JSON request bodies. Missing/invalid credentials, including an empty server token, return 401. Browser sessions, login cookies and CSRF tokens are not used by these API routes.

All routes are under `/api/printing`. The authenticated printing routes share a 120 requests/minute per-IP limit with a printing-specific key prefix, using the existing Laravel cache. Polling every 3 seconds consumes about 20 requests/minute, leaving room for callbacks. Other route/global limits are unchanged and use separate keys; 429 means back off. Poll responses have `Cache-Control: no-store`; configure proxies/CDNs to bypass caching for this API. Do not log bearer credentials or receipt bodies.

## Pull protocol

1. Every roughly 3 seconds, `POST /api/printing/next` with `{}`. It returns 204 if no unclaimed or expired-lease pending job exists, or 200 with `{"attempt": 1, "payload": <snapshot below>}`. POST is intentional because the first fetch records a delivery attempt.
2. Persist the job to durable local storage, deduplicated by `payload.print_job_id`, **before** reporting acceptance. `POST /api/printing/{UUID}/accepted` with `{"attempt": 1}` returns 200 with `{"print_job_id":"<UUID>","status":"sent"}`. Repeat this call if its response is lost.
3. If the worker cannot durably accept the job, `POST /api/printing/{UUID}/failed` with `{"attempt": 1, "error":"storage_unavailable"}` returns 200 with `{"print_job_id":"<UUID>","status":"failed"}`. Allowed error codes: `storage_unavailable`, `invalid_payload`, `unsupported_version`. No arbitrary error text or physical-printer failures belong here.

Polling atomically claims the oldest available pending row by created_at then UUID for **60 seconds**, using a row lock and the existing `last_attempt_at` field. Claimed rows remain `pending` but are excluded from polling until the lease expires; other available jobs can be fetched in the meantime. At exactly 60 seconds, a job with no accepted/failed callback becomes eligible again automatically, without a scheduler. The next fetch increments `attempt` and returns the same UUID and payload. After a lost poll response the job becomes eligible after the lease; actual redelivery depends on the next poll and any older eligible jobs.

`attempt` is delivery metadata outside the immutable payload. The existing `attempts` counter increments on each claim, including lease recovery and the first fetch after an explicit failed-job retry. The worker must echo the attempt received; deduplication remains by job UUID, never by attempt. Run one logical shop worker with one durable deduplication store; leases do not make independent stores safe against duplicate physical printing.

Reports require a fetched, current attempt. Duplicate reports for the same outcome/attempt return 200 without changing timestamps or errors. Conflicting outcomes return 409; a failure cannot downgrade `sent`, and acceptance cannot bypass an explicitly failed attempt. An expired pending lease returns 409 even before reclaim. A stale callback after reclaim or an operator retry returns 409, including before the new attempt is fetched. Already-resolved duplicate acknowledgements remain idempotent even after 60 seconds. Validation errors return 422, unknown UUIDs 404, and reports that would change an expired job return 410. Jobs with removed payloads are never offered. An already-sent acknowledgement remains repeatable after retention.

A worker receiving 409 must not blindly send the opposite outcome or print again: reconcile its durable local state with subsequent polling/operator intervention. After a lease expires or a failed attempt is explicitly retried, a job already stored locally is acknowledged under its new attempt without enqueuing another receipt.

## Immutable payload v1

```json
{
  "version": 1,
  "type": "kitchen",
  "print_job_id": "c44171d8-9fae-4d7c-8749-cec268e34b27",
  "order_id": 123,
  "display_number": 7,
  "placed_at": "2026-09-05T09:30:00.000000Z",
  "accepted_at": "2026-09-05T09:31:00.000000Z",
  "timezone": "Europe/Athens",
  "customer": {"name": "Μαρία"},
  "notes": null,
  "items": [{
    "name": "Καφές",
    "quantity": 2,
    "options": [{"group": "Ζάχαρη", "value": "Σκέτος"}],
    "notes": "Λίγος πάγος"
  }]
}
```

IDs/order numbers/quantities/version are JSON integers except the job UUID string. Timestamps are UTC ISO 8601; timezone is the presentation timezone. Notes are nullable strings. Items/options are ordered arrays; absent options are `[]`. The daily display number is not an identity. The worker must handle an empty items array without treating it as a protocol failure (legacy orders can lack items). UTF-8 text is data, never ESC/POS instructions.

## Operations and status

Deploy the existing migration through the application's normal deployment procedure (`php artisan migrate --force`), configure `PRINT_WORKER_TOKEN`, and refresh cached configuration as usual. Point the worker at the Laravel HTTPS base URL. No additional migration is needed for the pull transport or lease: they use existing fields. The only active payload type is `kitchen`; no aliases, mappings or compatibility migrations are supported.

The former `printing:send` command and scheduler dispatch are removed. Remove any custom cron invocation of that command. The old worker endpoint setting is no longer read and can be removed from deployment secrets. No Laravel print dispatcher process is required.

- `pending`: awaiting durable acceptance; temporarily hidden from polling while its 60-second claim is active.
- `sent`: worker durably accepted responsibility, **not** proof of physical printing.
- `failed`: worker reported failure to accept; excluded from polling until explicitly retried.
- `php artisan printing:retry-failed`: requeue failed jobs with retained payloads.
- `php artisan printing:retry-failed <UUID>`: requeue only that failed job.

Retry changes status to pending and clears last_attempt_at/last_error; it preserves UUID, payload and attempts counter. The next fetch starts the next attempt. Sent jobs and expired snapshots cannot be requeued. Retry commands do not send network requests. Resolve failures before retrying; there is no automatic failed-job loop.

Inspect `print_jobs` (`id`, `order_id`, `status`, `attempts`, `last_attempt_at`, `sent_at`, `last_error`) through normal database operations/Tinker. `attempts` now counts delivery cycles offered by polling, not individual HTTP requests. Short database row locks serialize claim/report/retention changes; no lock is held across worker network or USB operations.

Existing order retention clears the job payload, retaining the unique ID/order association as a deduplication tombstone. Unsent expired jobs become failed/payload_expired. Sent status is retained. Job foreign keys prevent deleting orders and silently losing deduplication history. The worker must separately remove receipt PII according to retention while retaining deduplication IDs.

## Rust worker next

1. Implement the outbound poll loop, bearer authentication, timeouts/backoff and the three requests above. Poll about every 3 seconds, and retry lost acknowledgements safely.
2. Persist jobs with a unique UUID atomically before acceptance. Compare duplicate payloads semantically. Repeated fetches, concurrent requests and restarts must reuse the stored job without enqueuing another print. Never acknowledge a failed durable write.
3. Own layout, Greek character rendering, encoding, ESC/POS, USB and physical device failures entirely in Rust. Printer errors after acceptance stay in the worker and never downgrade Laravel `sent`.
4. Persist the printing lifecycle. A crash during USB output creates an uncertain physical outcome: do not automatically replay that job after restart. Require operator reconciliation. Exactly-once physical printing cannot be guaranteed by HTTP or Laravel.
5. Retain deduplication tombstones for at least as long as Laravel can retry (including restored backups). Test lost poll/ack responses, repeated jobs across attempts, restarts before/after acceptance and crashes during printer output before production use.

This contract reports durable handoff only. Printer completion monitoring, deliberate reprints and other receipt types require an explicit later extension. No Rust worker is implemented here.
