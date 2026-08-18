<x-filament-widgets::widget data-store-orders-status>
    <x-filament::section>
        <div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    Κατάσταση Παραγγελιών
                </p>

                <p @class([
                    'mt-1 text-xl font-black tracking-tight',
                    'text-success-600 dark:text-success-400' => $isAcceptingOrders,
                    'text-danger-600 dark:text-danger-400' => ! $isAcceptingOrders,
                ])>
                    {{ $isAcceptingOrders
                        ? 'ΔΕΧΟΜΑΣΤΕ ΠΑΡΑΓΓΕΛΙΕΣ'
                        : 'ΔΕΝ ΔΕΧΟΜΑΣΤΕ ΠΑΡΑΓΓΕΛΙΕΣ' }}
                </p>

                @if($manualAcceptingOrders && ! $isAcceptingOrders)
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Ο χειροκίνητος διακόπτης είναι ενεργός, αλλά το εβδομαδιαίο ωράριο είναι κλειστό.
                    </p>
                @endif
            </div>

            <x-filament::button
                type="button"
                wire:click="toggleAcceptingOrders"
                wire:loading.attr="disabled"
                :color="$manualAcceptingOrders ? 'danger' : 'success'"
                size="lg"
            >
                {{ $manualAcceptingOrders ? 'Παύση παραγγελιών' : 'Άνοιγμα παραγγελιών' }}
            </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
