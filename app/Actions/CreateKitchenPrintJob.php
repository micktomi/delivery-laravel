<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\PrintJob;
use Illuminate\Support\Str;

class CreateKitchenPrintJob
{
    // Called only inside the acceptance transaction, with the order locked.
    public function execute(Order $order): PrintJob
    {
        if ($existing = PrintJob::where('order_id', $order->id)->first()) {
            return $existing;
        }

        $id = (string) Str::uuid();

        return PrintJob::create([
            'id' => $id,
            'order_id' => $order->id,
            'payload' => [
                'version' => 1,
                'type' => 'kitchen',
                'print_job_id' => $id,
                'order_id' => $order->id,
                'display_number' => $order->display_number,
                'placed_at' => $order->placed_at->toISOString(),
                'accepted_at' => now()->toISOString(),
                'timezone' => config('app.timezone'),
                'customer' => ['name' => $order->customer_name],
                'notes' => $order->notes,
                'items' => $order->items()->orderBy('id')->get()->map(fn ($item) => [
                    'name' => $item->product_name,
                    'quantity' => (int) $item->quantity,
                    'options' => collect($item->selected_options ?? [])->map(fn ($option) => [
                        'group' => $option['group'],
                        'value' => $option['value'],
                    ])->values()->all(),
                    'notes' => $item->notes,
                ])->all(),
            ],
        ]);
    }
}
