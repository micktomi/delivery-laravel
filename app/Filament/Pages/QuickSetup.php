<?php

namespace App\Filament\Pages;

use App\Actions\ApplyQuickSetupPreset;
use App\Enums\QuickSetupPreset;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Operator-only provisioning page: applies one of the two demo presets
 * (Grill House / Restaurant) via ApplyQuickSetupPreset. Reachable only by
 * an admin — the whole /admin panel is already gated to is_admin===true by
 * User::canAccessPanel(), so this needs no RBAC of its own.
 */
class QuickSetup extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $navigationLabel = 'Γρήγορο Στήσιμο';

    protected static ?string $title = 'Γρήγορο Στήσιμο';

    protected static ?int $navigationSort = 91;

    protected static string $view = 'filament.pages.quick-setup';

    /** @var array<string, mixed>|null */
    public ?array $data = [
        'preset' => null,
    ];

    public function mount(): void
    {
        $this->form->fill($this->data);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Select::make('preset')
                ->label('Πρότυπο')
                ->options(QuickSetupPreset::class)
                ->required()
                ->live()
                ->native(false),
            Placeholder::make('preview')
                ->label('Θα δημιουργηθούν')
                ->content(fn (Get $get): HtmlString => $this->preview($get('preset'))),
        ])->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('apply')
                ->label('Εφαρμογή προτύπου')
                ->color('primary')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalHeading('Εφαρμογή προτύπου')
                ->modalDescription(fn (): HtmlString => new HtmlString(
                    'Θα δημιουργηθούν κατηγορίες, ομάδες επιλογών και τιμές επιλογών όπως φαίνεται παρακάτω. '
                    .'Καμία υπάρχουσα κατηγορία, προϊόν ή ομάδα επιλογών δεν διαγράφεται ή αντικαθίσταται.'
                    .$this->preview($this->data['preset'] ?? null),
                ))
                ->modalSubmitActionLabel('Ναι, εφάρμοσέ το')
                ->action(fn () => $this->apply()),
        ];
    }

    public function apply(): void
    {
        $data = $this->form->getState();
        $preset = QuickSetupPreset::from($data['preset']);

        try {
            $result = app(ApplyQuickSetupPreset::class)->execute($preset);
        } catch (ValidationException $e) {
            Notification::make()
                ->title('Δεν εφαρμόστηκε το πρότυπο')
                ->body($e->validator->errors()->first())
                ->danger()
                ->send();

            return;
        }

        if ($result['categories'] === [] && $result['option_groups'] === [] && $result['option_values'] === 0) {
            Notification::make()
                ->title('Δεν προστέθηκε τίποτα νέο')
                ->body('Όλες οι κατηγορίες και οι ομάδες επιλογών του προτύπου υπήρχαν ήδη.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title('Το πρότυπο εφαρμόστηκε')
            ->body(sprintf(
                '%d νέες κατηγορίες, %d νέες ομάδες επιλογών, %d νέες τιμές επιλογών.',
                count($result['categories']),
                count($result['option_groups']),
                $result['option_values'],
            ))
            ->success()
            ->send();
    }

    private function preview(?string $presetValue): HtmlString
    {
        $preset = QuickSetupPreset::tryFrom((string) $presetValue);

        if ($preset === null) {
            return new HtmlString('<p class="text-sm text-gray-500">Επιλέξτε πρότυπο για να δείτε τι θα δημιουργηθεί.</p>');
        }

        $definition = ApplyQuickSetupPreset::definition($preset);

        $categories = collect($definition['categories'])
            ->map(fn (string $name) => e($name))
            ->implode(', ');

        $groups = collect($definition['option_groups'])->map(function (array $group): string {
            $required = $group['is_required'] ? 'υποχρεωτική' : 'προαιρετική';
            $selection = $group['selection']->value === 'multi' ? 'πολλαπλή επιλογή' : 'μονή επιλογή';
            $values = collect($group['values'])->map(fn (array $v) => e($v['name']))->implode(', ');

            return '<li><strong>'.e($group['name'])."</strong> ({$required}, {$selection}): {$values}</li>";
        })->implode('');

        return new HtmlString(
            '<div class="space-y-2 text-sm">'
            ."<p><strong>Κατηγορίες:</strong> {$categories}</p>"
            .'<p><strong>Ομάδες επιλογών:</strong></p>'
            ."<ul class=\"list-disc pl-5 space-y-1\">{$groups}</ul>"
            .'</div>',
        );
    }
}
