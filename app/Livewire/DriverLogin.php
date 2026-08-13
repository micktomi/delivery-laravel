<?php

namespace App\Livewire;

use App\Models\Driver;
use App\Models\DriverShift;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class DriverLogin extends Component
{
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
            'pin' => ['required', 'digits:6'],
        ]);

        $key = 'driver-login:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('pin', 'Πάρα πολλές προσπάθειες. Δοκιμάστε ξανά σε '.RateLimiter::availableIn($key).' δευτερόλεπτα.');

            return;
        }

        $driver = Driver::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (Driver $candidate): bool => Hash::check($this->pin, $candidate->pin));

        if (! $driver) {
            RateLimiter::hit($key, 60);
            $this->addError('pin', 'Ο κωδικός PIN δεν είναι σωστός.');

            return;
        }

        RateLimiter::clear($key);
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

    public function render()
    {
        return view('livewire.driver-login')->layout('layouts.app');
    }
}
