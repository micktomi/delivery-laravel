<?php

namespace App\Filament\Resources;

use App\Enums\SelectionType;
use App\Filament\Resources\OptionGroupResource\Pages;
use App\Filament\Resources\OptionGroupResource\RelationManagers;
use App\Models\OptionGroup;
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
