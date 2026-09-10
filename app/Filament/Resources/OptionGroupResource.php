<?php

namespace App\Filament\Resources;

use App\Enums\SelectionType;
use App\Filament\Resources\OptionGroupResource\Pages;
use App\Filament\Resources\OptionGroupResource\RelationManagers;
use App\Models\OptionGroup;
use App\Models\OptionValue;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OptionGroupResource extends Resource
{
    protected static ?string $model = OptionGroup::class;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Ομάδες Επιλογών';

    protected static ?string $modelLabel = 'Ομάδα Επιλογών';

    protected static ?string $pluralModelLabel = 'Ομάδες Επιλογών';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Βασικά στοιχεία')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Όνομα')
                        ->required(),
                    Forms\Components\Select::make('selection')
                        ->label('Τύπος επιλογής')
                        ->options([
                            SelectionType::Single->value => 'Μονή (radio)',
                            SelectionType::Multi->value => 'Πολλαπλή (checkbox)',
                        ])
                        ->required()
                        ->live(),
                    Forms\Components\Toggle::make('is_required')
                        ->label('Υποχρεωτική'),
                    Forms\Components\TextInput::make('min_select')
                        ->label('Ελάχιστες επιλογές')
                        ->numeric()
                        ->nullable()
                        ->visible(fn (Forms\Get $get) => $get('selection') === SelectionType::Multi->value),
                    Forms\Components\TextInput::make('max_select')
                        ->label('Μέγιστες επιλογές')
                        ->numeric()
                        ->nullable()
                        ->visible(fn (Forms\Get $get) => $get('selection') === SelectionType::Multi->value),
                    Forms\Components\TextInput::make('sort_order')
                        ->label('Σειρά')
                        ->numeric()
                        ->default(0),
                ])
                ->columns(2),
            Forms\Components\Section::make('Εξάρτηση από άλλη ομάδα')
                ->description('Προαιρετικό. Έχει νόημα μόνο όταν και οι δύο ομάδες βρίσκονται στο ίδιο προϊόν — διαφορετικά δεν κάνει τίποτα.')
                ->schema([
                    Forms\Components\Select::make('hidden_when_option_value_id')
                        ->label('Κρυφή όταν έχει επιλεγεί')
                        ->helperText('Η ομάδα αυτή αγνοείται εντελώς (δεν εμφανίζεται, δεν απαιτείται) όσο αυτή η τιμή —από οποιαδήποτε άλλη ομάδα του ίδιου προϊόντος— είναι επιλεγμένη. Π.χ. "Σάλτσα" κρυφή όταν "Χωρίς σάλτσα" είναι επιλεγμένο.')
                        ->options(fn (?OptionGroup $record) => OptionValue::query()
                            ->with('optionGroup')
                            ->when($record, fn ($query) => $query->where('option_group_id', '!=', $record->id))
                            ->get()
                            ->mapWithKeys(fn (OptionValue $value) => [
                                $value->id => $value->optionGroup->name.': '.$value->name,
                            ]))
                        ->searchable()
                        ->nullable(),
                    Forms\Components\Select::make('combine_display_with_option_group_id')
                        ->label('Εμφάνιση μαζί με ομάδα')
                        ->helperText('Η επιλογή αυτής της ομάδας εμφανίζεται σαν προσθήκη πάνω στην επιλογή της επιλεγμένης ομάδας εδώ, αντί να αναφέρεται ξεχωριστά (π.χ. "Μέτριος με Στέβια" αντί για "Μέτριος" και "Στέβια" σε ξεχωριστές γραμμές).')
                        ->options(fn (?OptionGroup $record) => OptionGroup::query()
                            ->when($record, fn ($query) => $query->whereKeyNot($record->id))
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->nullable(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Όνομα')->searchable(),
                Tables\Columns\TextColumn::make('selection')->label('Τύπος')
                    ->formatStateUsing(fn ($state) => $state instanceof SelectionType ? $state->value : $state),
                Tables\Columns\IconColumn::make('is_required')->label('Υποχρεωτική')->boolean(),
                Tables\Columns\TextColumn::make('optionValues_count')
                    ->label('Τιμές')
                    ->counts('optionValues'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\OptionValuesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOptionGroups::route('/'),
            'create' => Pages\CreateOptionGroup::route('/create'),
            'edit' => Pages\EditOptionGroup::route('/{record}/edit'),
        ];
    }
}
