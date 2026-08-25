<?php

namespace App\Livewire;

use App\Http\Middleware\EnsureKitchenAvailabilityAccess;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;
use RuntimeException;

class KitchenAvailabilityLogin extends Component
{
    /** Failed attempts from one device before the configured hash is no longer checked. */
    private const MAX_ATTEMPTS_PER_DEVICE = 5;

    /** Backstop against attempts distributed across multiple addresses. */
    private const MAX_ATTEMPTS_TOTAL = 20;

    private const LOCKOUT_WINDOW = 900;

    public string $pin = '';

    public function mount(): void
    {
        if (EnsureKitchenAvailabilityAccess::allows()) {
            $this->redirectRoute('kitchen.availability');
        }
    }

    public function login(): void
    {
        $this->validate([
            'pin' => ['required', 'digits:6'],
        ], [
            'pin.required' => 'Πληκτρολόγησε το PIN.',
            'pin.digits' => 'Το PIN πρέπει να έχει 6 ψηφία.',
        ]);

        $accountKey = 'kitchen-availability-login';
        $deviceKey = $accountKey.':'.request()->ip();

        foreach ([[$deviceKey, self::MAX_ATTEMPTS_PER_DEVICE], [$accountKey, self::MAX_ATTEMPTS_TOTAL]] as [$key, $maximum]) {
            if (RateLimiter::tooManyAttempts($key, $maximum)) {
                $this->addError('pin', 'Πάρα πολλές προσπάθειες. Δοκιμάστε ξανά σε '
                    .$this->waitText(RateLimiter::availableIn($key)).'.');

                return;
            }
        }

        $pinHash = config('kitchen.availability_pin_hash');
        $pinMatches = false;

        if (is_string($pinHash) && $pinHash !== '') {
            try {
                $pinMatches = Hash::check($this->pin, $pinHash);
            } catch (RuntimeException) {
                // A missing or malformed deployment hash must fail closed, not
                // turn a staff login attempt into an application error page.
            }
        }

        if (! $pinMatches) {
            RateLimiter::hit($deviceKey, self::LOCKOUT_WINDOW);
            RateLimiter::hit($accountKey, self::LOCKOUT_WINDOW);

            $this->reset('pin');
            $this->addError('pin', 'Ο κωδικός PIN δεν είναι σωστός.');

            return;
        }

        RateLimiter::clear($deviceKey);
        RateLimiter::clear($accountKey);

        session()->regenerate();
        session([
            EnsureKitchenAvailabilityAccess::SESSION_KEY => EnsureKitchenAvailabilityAccess::fingerprint($pinHash),
        ]);

        $this->reset('pin');
        $this->redirectRoute('kitchen.availability');
    }

    public function render()
    {
        return view('livewire.kitchen-availability-login')
            ->layout('layouts.app');
    }

    private function waitText(int $seconds): string
    {
        return $seconds >= 60
            ? max(1, (int) ceil($seconds / 60)).' λεπτά'
            : max(1, $seconds).' δευτερόλεπτα';
    }
}
