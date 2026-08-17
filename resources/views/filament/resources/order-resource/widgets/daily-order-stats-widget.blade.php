<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                Στατιστικά ημέρας — {{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('d/m/Y') }}
            </h2>

            <div class="flex items-center gap-2">
                <label for="daily-order-stats-date" class="text-sm text-gray-500 dark:text-gray-400">
                    Ημερομηνία
                </label>
                <x-filament::input.wrapper class="w-44">
                    <x-filament::input
                        id="daily-order-stats-date"
                        type="date"
                        wire:model.live="date"
                    />
                </x-filament::input.wrapper>
            </div>
        </div>

        @php($stats = $this->getStats())

        <div class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Παραγγελίες</span>
                <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $stats['total'] }}</div>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Ολοκληρώθηκαν / Έφυγαν</span>
                <div class="mt-1 text-2xl font-semibold text-success-600 dark:text-success-400">{{ $stats['completed_or_out'] }}</div>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Ακυρώθηκαν</span>
                <div class="mt-1 text-2xl font-semibold text-danger-600 dark:text-danger-400">{{ $stats['cancelled'] }}</div>
            </div>

            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Τζίρος</span>
                <div class="mt-1 text-2xl font-semibold text-warning-600 dark:text-warning-400">{{ number_format($stats['revenue'], 2) }} €</div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
