<?php

namespace App\Console\Commands;

use App\Models\Driver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateDriver extends Command
{
    protected $signature = 'driver:create {name? : Το όνομα του οδηγού}';

    protected $description = 'Δημιουργεί οδηγό με εξαψήφιο, hashed PIN.';

    public function handle(): int
    {
        $name = trim((string) ($this->argument('name') ?: $this->ask('Όνομα οδηγού')));
        $pin = (string) $this->secret('Εξαψήφιο PIN');

        if ($name === '' || ! preg_match('/^\d{6}$/', $pin)) {
            $this->error('Απαιτούνται όνομα και εξαψήφιο αριθμητικό PIN.');

            return self::FAILURE;
        }

        $duplicate = Driver::query()->get()->contains(
            fn (Driver $driver): bool => Hash::check($pin, $driver->pin),
        );

        if ($duplicate) {
            $this->error('Το PIN χρησιμοποιείται ήδη από άλλον οδηγό.');

            return self::FAILURE;
        }

        $driver = Driver::query()->create(['name' => $name, 'pin' => $pin]);
        $this->info('Δημιουργήθηκε ο οδηγός '.$driver->name.'.');

        return self::SUCCESS;
    }
}
