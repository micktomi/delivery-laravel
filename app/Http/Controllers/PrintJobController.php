<?php

namespace App\Http\Controllers;

use App\Models\PrintJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class PrintJobController extends Controller
{
    public function next(): Response
    {
        return DB::transaction(function (): Response {
            $job = PrintJob::where('status', 'pending')->whereNotNull('payload')
                ->where(fn ($query) => $query->whereNull('last_attempt_at')
                    ->orWhere('last_attempt_at', '<=', now()->subSeconds(60)))
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();

            if (! $job) {
                return response()->noContent();
            }

            // A claim lasts 60 seconds. Every reclaim gets a new attempt;
            // the payload and UUID remain unchanged for worker deduplication.
            $job->attempts++;
            $job->last_attempt_at = now();
            $job->save();

            return response()->json(['attempt' => $job->attempts, 'payload' => $job->payload]);
        });
    }

    public function accepted(Request $request, string $id): Response
    {
        return $this->report($request, $id, 'sent');
    }

    public function failed(Request $request, string $id): Response
    {
        return $this->report($request, $id, 'failed');
    }

    private function report(Request $request, string $id, string $status): Response
    {
        $data = $request->validate([
            'attempt' => ['required', 'integer', 'min:1'],
            // Only bounded transport error codes, never printer errors or PII.
            'error' => $status === 'failed'
                ? ['required', Rule::in(['storage_unavailable', 'invalid_payload', 'unsupported_version'])]
                : ['prohibited'],
        ]);

        return DB::transaction(function () use ($id, $data, $status): Response {
            $job = PrintJob::whereKey($id)->lockForUpdate()->firstOrFail();

            // A delayed callback from before an operator retry cannot affect it.
            abort_if($job->last_attempt_at === null || $job->attempts !== (int) $data['attempt'], 409, 'Stale delivery attempt.');

            if ($job->status === $status) {
                return response()->json(['print_job_id' => $job->id, 'status' => $job->status]);
            }

            abort_if($job->payload === null, 410, 'Payload expired.');
            abort_unless($job->status === 'pending', 409, 'Delivery already resolved.');

            abort_if($job->last_attempt_at->lte(now()->subSeconds(60)), 409, 'Delivery lease expired.');

            $job->status = $status;
            $job->last_error = $status === 'failed' ? $data['error'] : null;
            $job->sent_at = $status === 'sent' ? now() : null;
            $job->save();

            return response()->json(['print_job_id' => $job->id, 'status' => $job->status]);
        });
    }
}
