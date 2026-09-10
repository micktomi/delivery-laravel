<?php

namespace App\Filament\Pages;

use App\Models\StoreSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

class StoreSettings extends Page implements HasForms
{
    use InteractsWithForms;

    /** @var array<string, string> */
    private const WEEKDAYS = [
        'monday' => 'Δευτέρα',
        'tuesday' => 'Τρίτη',
        'wednesday' => 'Τετάρτη',
        'thursday' => 'Πέμπτη',
        'friday' => 'Παρασκευή',
        'saturday' => 'Σάββατο',
        'sunday' => 'Κυριακή',
    ];

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Ρυθμίσεις Καταστήματος';

    protected static ?string $title = 'Ρυθμίσεις Καταστήματος';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.store-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->formData(StoreSetting::current()));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Ταυτότητα και εμφάνιση')
                    ->description('Το όνομα, το λογότυπο και τα χρώματα εμφανίζονται στο δημόσιο storefront χωρίς νέο frontend build.')
                    ->schema([
                        TextInput::make('store_name')
                            ->label('Όνομα καταστήματος')
                            ->required()
                            ->maxLength(StoreSetting::STORE_NAME_MAX_LENGTH),
                        FileUpload::make('logo_path')
                            ->label('Λογότυπο')
                            ->helperText('Προαιρετικό · JPEG, PNG ή WebP έως 2 MB.')
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->disk('public')
                            ->directory('branding')
                            ->visibility('public')
                            ->maxSize(2048),
                        TextInput::make('brand_primary')
                            ->label('Βασικό χρώμα')
                            ->required()
                            ->maxLength(7)
                            ->regex('/^#[0-9a-fA-F]{6}$/')
                            ->helperText('Μορφή #RRGGBB, π.χ. #D97706.')
                            ->dehydrateStateUsing(fn (string $state): string => strtoupper(trim($state))),
                        TextInput::make('brand_accent')
                            ->label('Δευτερεύον χρώμα')
                            ->required()
                            ->maxLength(7)
                            ->regex('/^#[0-9a-fA-F]{6}$/')
                            ->helperText('Μορφή #RRGGBB, π.χ. #1C1206.')
                            ->dehydrateStateUsing(fn (string $state): string => strtoupper(trim($state))),
                    ])
                    ->columns(2),
                Section::make('Εβδομαδιαίο ωράριο')
                    ->description('Τα διαστήματα εφαρμόζονται στη ζώνη ώρας Europe/Athens και μπορούν να περνούν τα μεσάνυχτα.')
                    ->schema($this->weekdaySections()),
                Section::make('Όταν το κατάστημα είναι κλειστό')
                    ->schema([
                        Textarea::make('closed_message')
                            ->label('Μήνυμα όταν το κατάστημα είναι κλειστό')
                            ->maxLength(1000)
                            ->rows(3),
                        Placeholder::make('timezone')
                            ->label('Ζώνη ώρας')
                            ->content('Europe/Athens'),
                    ]),
            ])
            ->statePath('data');
    }

    public function copyMondayToAllDays(): void
    {
        $data = $this->data ?? [];
        $mondaySchedule = $data['schedule']['monday'] ?? [
            'open' => false,
            'intervals' => [],
        ];

        foreach (array_keys(self::WEEKDAYS) as $weekday) {
            if ($weekday === 'monday') {
                continue;
            }

            $data['schedule'][$weekday]['open'] = (bool) ($mondaySchedule['open'] ?? false);
            $data['schedule'][$weekday]['intervals'] = array_values(
                $mondaySchedule['intervals'] ?? [],
            );
        }

        $this->form->fill($data);

        Notification::make()
            ->title('Το ωράριο της Δευτέρας αντιγράφηκε')
            ->body('Αντιγράφηκαν η κατάσταση και τα διαστήματα της Δευτέρας.')
            ->success()
            ->send();
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $openingHours = [];

        foreach (array_keys(self::WEEKDAYS) as $weekday) {
            $day = $data['schedule'][$weekday] ?? [];

            if (! (bool) ($day['open'] ?? false)) {
                $openingHours[$weekday] = [];

                continue;
            }

            $openingHours[$weekday] = [];

            foreach ($day['intervals'] ?? [] as $index => $interval) {
                $open = (string) ($interval['open'] ?? '');
                $close = (string) ($interval['close'] ?? '');

                if ($open === $close) {
                    throw ValidationException::withMessages([
                        "data.schedule.{$weekday}.intervals.{$index}.close" => 'Η ώρα λήξης πρέπει να διαφέρει από την ώρα έναρξης.',
                    ]);
                }

                $openingHours[$weekday][] = [
                    'open' => $open,
                    'close' => $close,
                ];
            }
        }

        $closedMessage = trim((string) ($data['closed_message'] ?? ''));
        $settings = StoreSetting::current();
        $settings->update([
            'store_name' => trim((string) $data['store_name']),
            'logo_path' => $data['logo_path'] ?? $settings->logo_path,
            'brand_primary' => strtoupper(trim((string) $data['brand_primary'])),
            'brand_accent' => strtoupper(trim((string) $data['brand_accent'])),
            'opening_hours' => $openingHours,
            'closed_message' => $closedMessage === '' ? null : $closedMessage,
        ]);

        $this->form->fill($this->formData($settings->refresh()));

        Notification::make()
            ->title('Οι ρυθμίσεις καταστήματος αποθηκεύτηκαν')
            ->success()
            ->send();
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('copyMondayToAllDays')
                ->label('Αντιγραφή Δευτέρας σε όλες τις ημέρες')
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->requiresConfirmation()
                ->action('copyMondayToAllDays'),
        ];
    }

    /** @return array<Section> */
    private function weekdaySections(): array
    {
        $sections = [];

        foreach (self::WEEKDAYS as $weekday => $label) {
            $sections[] = Section::make(
                fn (Get $get): string => $this->weekdaySummary(
                    $label,
                    (bool) $get("schedule.{$weekday}.open"),
                    $get("schedule.{$weekday}.intervals"),
                ),
            )
                ->statePath("schedule.{$weekday}")
                ->schema([
                    Toggle::make('open')
                        ->label('Ανοιχτά')
                        ->live(),
                    Repeater::make('intervals')
                        ->label('Διαστήματα λειτουργίας')
                        ->schema([
                            TimePicker::make('open')
                                ->label('Έναρξη')
                                ->seconds(false)
                                ->native(false)
                                ->displayFormat('H:i')
                                ->format('H:i')
                                ->required(),
                            TimePicker::make('close')
                                ->label('Λήξη')
                                ->seconds(false)
                                ->native(false)
                                ->displayFormat('H:i')
                                ->format('H:i')
                                ->required(),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Προσθήκη διαστήματος')
                        ->reorderable(false)
                        ->required(fn (Get $get): bool => (bool) $get('open'))
                        ->minItems(1)
                        ->hidden(fn (Get $get): bool => ! (bool) $get('open')),
                ])
                ->columns(1)
                ->collapsible()
                ->collapsed();
        }

        return $sections;
    }

    /**
     * @return array{
     *     schedule: array<string, array{open: bool, intervals: list<array{open: string, close: string}>}>,
     *     store_name: string,
     *     logo_path: ?string,
     *     brand_primary: string,
     *     brand_accent: string,
     *     closed_message: ?string
     * }
     */
    private function formData(StoreSetting $settings): array
    {
        $openingHours = $settings->opening_hours;
        $schedule = [];

        foreach (array_keys(self::WEEKDAYS) as $weekday) {
            $intervals = [];

            foreach ($openingHours[$weekday] ?? [] as $interval) {
                if (! is_array($interval)) {
                    continue;
                }

                $intervals[] = [
                    'open' => (string) ($interval['open'] ?? $interval[0] ?? ''),
                    'close' => (string) ($interval['close'] ?? $interval[1] ?? ''),
                ];
            }

            $schedule[$weekday] = [
                'open' => $intervals !== [],
                'intervals' => $intervals,
            ];
        }

        return [
            'store_name' => $settings->displayName(),
            'logo_path' => $settings->logo_path,
            'brand_primary' => $settings->brandPrimary(),
            'brand_accent' => $settings->brandAccent(),
            'schedule' => $schedule,
            'closed_message' => $settings->closed_message,
        ];
    }

    private function weekdaySummary(string $label, bool $open, mixed $intervals): string
    {
        if (! $open) {
            return $label.' · Κλειστά';
        }

        $summaries = [];

        foreach (is_array($intervals) ? $intervals : [] as $interval) {
            if (! is_array($interval)) {
                continue;
            }

            $startsAt = $this->summaryTime($interval['open'] ?? null);
            $endsAt = $this->summaryTime($interval['close'] ?? null);

            if ($startsAt !== '' && $endsAt !== '') {
                $summaries[] = $startsAt.'–'.$endsAt;
            }
        }

        return $summaries === []
            ? $label.' · Ανοιχτά'
            : $label.' · '.implode(', ', $summaries);
    }

    private function summaryTime(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        if (preg_match('/(?:^|[ T])(\d{2}:\d{2})(?::\d{2})?$/D', $value, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }
}
