<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnonymizeExpiredOrderPersonalData extends Command
{
    protected $signature = 'orders:anonymize-personal-data';

    protected $description = 'Anonymize PII from old terminal orders while retaining financial records.';

    public function handle(): int
    {
        $days = (int) config('retention.orders.anonymization_days', 0);

        if ($days <= 0) {
            $this->line('Order personal-data anonymization is disabled.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $anonymized = 0;

        Order::query()
            ->whereIn('status', [OrderStatus::Completed->value, OrderStatus::Cancelled->value])
            ->where('placed_at', '<=', $cutoff)
            ->whereNull('personal_data_anonymized_at')
            ->orderBy('id')
            ->eachById(function (Order $order) use ($cutoff, &$anonymized): void {
                $changed = DB::transaction(function () use ($order, $cutoff): bool {
                    $locked = Order::query()
                        ->whereKey($order->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $locked
                        || $locked->personal_data_anonymized_at !== null
                        || ! in_array($locked->status, [OrderStatus::Completed, OrderStatus::Cancelled], true)
                        || $locked->placed_at->isAfter($cutoff)) {
                        return false;
                    }

                    $locked->items()->update(['notes' => null]);
                    $locked->forceFill([
                        // These columns are non-nullable, so retain an explicit
                        // non-identifying marker rather than weakening schema.
                        'customer_name' => 'Ανωνυμοποιημένο',
                        'customer_email' => null,
                        'phone' => 'Ανωνυμοποιημένο',
                        'address' => 'Ανωνυμοποιημένη διεύθυνση',
                        'floor_bell' => null,
                        'notes' => null,
                        'personal_data_anonymized_at' => now(),
                    ])->save();

                    return true;
                });

                if ($changed) {
                    $anonymized++;
                }
            });

        $this->line("Anonymized {$anonymized} order(s).");

        return self::SUCCESS;
    }
}
