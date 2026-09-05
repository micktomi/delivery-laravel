<?php

namespace App\Console\Commands;

use App\Models\PrintJob;
use Illuminate\Console\Command;

class RetryFailedPrintJobs extends Command
{
    protected $signature = 'printing:retry-failed {id? : Retry only this print-job UUID}';

    protected $description = 'Make failed handoffs available for polling again, preserving their IDs and snapshots.';

    public function handle(): int
    {
        $count = PrintJob::where('status', 'failed')->whereNotNull('payload')
            ->when($this->argument('id'), fn ($query, $id) => $query->whereKey($id))
            ->update(['status' => 'pending', 'last_attempt_at' => null, 'last_error' => null]);

        $this->line("Requeued {$count} print job(s).");

        return self::SUCCESS;
    }
}
