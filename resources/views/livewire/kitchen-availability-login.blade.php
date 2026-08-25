<main class="grid min-h-dvh place-items-center bg-stone-100 px-4 py-8">
    <section class="w-full max-w-md rounded-3xl border border-stone-200 bg-white p-6 shadow-xl sm:p-8">
        <p class="text-xs font-black uppercase tracking-[0.18em] text-amber-700">Kitchen staff</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight text-stone-950">Διαθεσιμότητα προϊόντων</h1>
        <p class="mt-3 text-base leading-relaxed text-stone-600">Πληκτρολόγησε το εξαψήφιο PIN προσωπικού.</p>

        <form wire:submit="login" class="mt-8 space-y-5">
            <div>
                <label for="availability-pin" class="block text-base font-bold text-stone-800">PIN</label>
                <input
                    id="availability-pin"
                    wire:model="pin"
                    type="password"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    autofocus
                    class="mt-2 min-h-16 w-full rounded-2xl border-2 border-stone-300 px-4 text-center text-3xl font-black tracking-[0.35em] text-stone-950 focus:border-amber-600 focus:outline-none"
                >
                @error('pin')
                    <p class="mt-2 rounded-xl bg-red-50 px-4 py-3 text-base font-bold text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="login"
                class="min-h-16 w-full rounded-2xl bg-amber-600 px-5 py-4 text-xl font-black text-white shadow-sm transition hover:bg-amber-700 active:scale-[0.98] disabled:opacity-60"
            >
                Σύνδεση
            </button>
        </form>
    </section>
</main>
