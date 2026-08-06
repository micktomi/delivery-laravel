<x-filament-widgets::widget data-delivery-menu-dashboard-card>
    <x-filament::section>
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Delivery Menu</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Διαχείριση καταλόγου και παραγγελιών</p>
            </div>

            <div class="flex flex-wrap gap-x-4 gap-y-2">
                <x-filament::link color="primary" href="{{ route('menu') }}" icon="heroicon-m-arrow-top-right-on-square">
                    Προβολή δημόσιου μενού
                </x-filament::link>
                <x-filament::link color="primary" href="{{ route('kitchen') }}" icon="heroicon-m-queue-list">
                    Kitchen board
                </x-filament::link>
                <x-filament::link color="primary" href="{{ route('filament.admin.resources.orders.index') }}" icon="heroicon-m-clipboard-document-list">
                    Παραγγελίες
                </x-filament::link>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>