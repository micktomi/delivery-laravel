<?php

namespace App\Livewire;

use App\Models\Driver;
use App\Models\DriverShift;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Component;

class DriverLogin extends Component
{
    /** Failed attempts from one device before the PIN stops being checked at all. */
    private const MAX_ATTEMPTS_PER_DEVICE = 5;

    /** Backstop for the same driver guessed at from rotating addresses. */
    private const MAX_ATTEMPTS_PER_DRIVER = 20;

    private const LOCKOUT_WINDOW = 900;

    public string $driverId = '';

    public string $pin = '';

    public function mount(): void
    {
        if (Auth::guard('driver')->check()) {
            $this->redirectRoute('driver.dashboard');
        }
    }

    public function login(): void
    {
        $this->validate([
            'driverId' => ['required', Rule::exists('drivers', 'id')->where('is_active', true)],
            'pin' => ['required', 'digits:6'],
        ], [
            'driverId.required' => 'Διάλεξε το όνομά σου.',
            'driverId.exists' => 'Διάλεξε το όνομά σου.',
        ]);

        $driver = Driver::query()
            ->where('is_active', true)
            ->whereKey((int) $this->driverId)
            ->first();

        if (! $driver) {
            $this->addError('driverId', 'Διάλεξε το όνομά σου.');

            return;
        }

        // Naming the driver first is what makes the counting work: one hash is
        // checked per attempt, and the attempts belong to that one account
        // rather than to a PIN that any of the drivers might have owned.
        $accountKey = 'driver-login:'.$driver->getKey();
        $deviceKey = $accountKey.':'.request()->ip();

        foreach ([[$deviceKey, self::MAX_ATTEMPTS_PER_DEVICE], [$accountKey, self::MAX_ATTEMPTS_PER_DRIVER]] as [$key, $maximum]) {
            if (RateLimiter::tooManyAttempts($key, $maximum)) {
                $this->addError('pin', 'Πάρα πολλές προσπάθειες. Δοκιμάστε ξανά σε '
                    .$this->waitText(RateLimiter::availableIn($key)).'.');

                return;
            }
        }

        if (! Hash::check($this->pin, $driver->pin)) {
            RateLimiter::hit($deviceKey, self::LOCKOUT_WINDOW);
            RateLimiter::hit($accountKey, self::LOCKOUT_WINDOW);

            $this->addError('pin', 'Ο κωδικός PIN δεν είναι σωστός.');

            return;
        }

        RateLimiter::clear($deviceKey);
        RateLimiter::clear($accountKey);

        Auth::guard('driver')->login($driver);
        session()->regenerate();

        $shift = DriverShift::query()->create([
            'driver_id' => Auth::guard('driver')->id(),
            'started_at' => now(),
        ]);
        session(['driver_shift_id' => $shift->getKey()]);

        $this->reset('pin');
        $this->redirectRoute('driver.dashboard');
    }

    private function waitText(int $seconds): string
    {
        return $seconds >= 60
            ? max(1, (int) ceil($seconds / 60)).' λεπτά'
            : max(1, $seconds).' δευτερόλεπτα';
    }

    public function render()
    {
        return view('livewire.driver-login', [
            'drivers' => Driver::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ])->layout('layouts.app');
    }
}
