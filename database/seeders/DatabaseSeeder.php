<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->createAdmin();

        $this->call([
            OptionGroupSeeder::class,
            DemoMenuSeeder::class,
            BrownSugarSweetenerSeeder::class,
        ]);
    }

    /**
     * The admin is created from ADMIN_EMAIL / ADMIN_PASSWORD. There is no
     * default password: when none is supplied a random one is generated and
     * printed once, so a seeded install can never ship known credentials.
     */
    private function createAdmin(): void
    {
        $email = env('ADMIN_EMAIL') ?: 'admin@delivery.local';
        $password = env('ADMIN_PASSWORD');

        $user = User::query()->firstOrNew(['email' => $email]);
        $existed = $user->exists;
        $generated = false;

        // Re-seeding the menu must never reset a password the owner has changed.
        if (! $existed || filled($password)) {
            if (blank($password)) {
                $password = Str::password(16);
                $generated = true;
            }

            $user->password = Hash::make($password);
        }

        $user->name = $user->name ?: 'Admin';
        $user->email_verified_at ??= now();
        $user->is_admin = true;
        $user->save();

        $this->command?->info(($existed ? 'Admin kept: ' : 'Admin created: ').$email);

        if ($generated) {
            $this->command?->warn('Generated admin password (shown once): '.$password);
        }
    }
}
