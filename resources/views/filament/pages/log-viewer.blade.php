<x-filament-panels::page>
    @php($snapshot = $this->getLogSnapshot())

    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap gap-2" role="tablist" aria-label="Log source">
                <x-filament::button
                    type="button"
                    wire:click="selectSource('application')"
                    :color="$source === 'application' ? 'primary' : 'gray'"
                    size="sm"
                >
                    Laravel / Application
                </x-filament::button>

                <x-filament::button
                    type="button"
                    wire:click="selectSource('payments')"
                    :color="$source === 'payments' ? 'primary' : 'gray'"
                    size="sm"
                >
                    Payments / Viva
                </x-filament::button>
            </div>

            <x-filament::button
                type="button"
                wire:click="$refresh"
                color="gray"
                icon="heroicon-o-arrow-path"
                size="sm"
            >
                Ανανέωση
            </x-filament::button>
        </div>

        <x-filament::section>
            <div class="space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ $snapshot['label'] }}
                        </h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            @if($snapshot['filename'])
                                {{ $snapshot['filename'] }} · έως 500 τελευταίες γραμμές / 256 KB
                            @else
                                Δεν υπάρχει ακόμη διαθέσιμο log αρχείο.
                            @endif
                        </p>
                    </div>

                    <div class="w-full sm:w-80">
                        <x-filament::input.wrapper>
                            <x-filament::input
                                type="search"
                                wire:model.live.debounce.300ms="search"
                                placeholder="Αναζήτηση στις εμφανιζόμενες γραμμές"
                            />
                        </x-filament::input.wrapper>
                    </div>
                </div>

                @if($snapshot['limited'])
                    <p class="text-xs text-amber-600 dark:text-amber-400">
                        Εμφανίζεται μόνο το ασφαλές tail του αρχείου.
                    </p>
                @endif

                <pre class="max-h-[65vh] overflow-auto whitespace-pre-wrap break-words rounded-lg bg-gray-950 p-4 font-mono text-xs leading-5 text-gray-100">@if($snapshot['lines'] !== []){{ implode(PHP_EOL, $snapshot['lines']) }}@elseΔεν βρέθηκαν γραμμές για εμφάνιση.@endif</pre>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
