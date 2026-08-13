<main class="grid min-h-dvh place-items-center bg-[var(--sunken)] px-4 py-8">
    <section class="w-full max-w-sm rounded-3xl border border-[var(--hairline)] bg-white p-6 shadow-[0_20px_45px_-28px_rgb(28_18_6_/_0.35)]">
        <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-[var(--accent-text)]">Driver area</p>
        <h1 class="font-display mt-2 text-2xl font-extrabold tracking-tight">Σύνδεση οδηγού</h1>
        <p class="mt-2 text-sm leading-relaxed text-[var(--ink-soft)]">Πληκτρολόγησε το προσωπικό σου εξαψήφιο PIN.</p>

        <form wire:submit="login" class="mt-6 space-y-4">
            <div>
                <label for="driver-pin" class="text-sm font-semibold">PIN</label>
                <input
                    id="driver-pin"
                    wire:model="pin"
                    type="password"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    class="mt-1.5 w-full rounded-xl border border-[var(--hairline)] px-4 py-3 text-center text-xl font-bold tracking-[0.35em] focus:border-[var(--accent)] focus:outline-none"
                >
                @error('pin')
                    <p class="mt-1.5 text-sm font-medium text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="w-full rounded-xl px-4 py-3.5 text-sm font-semibold text-white" style="background: var(--accent);">
                Έναρξη βάρδιας
            </button>
        </form>
    </section>
</main>
