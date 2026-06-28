<?php

namespace App\Filament\Resources\OptionGroupResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class OptionValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'optionValues';
    protected static ?string $title = 'Τιμές Επιλογής';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Όνομα')
                ->required(),
            Forms\Components\TextInput::make('price_delta')
                ->label('Επιπλέον τιμή (€)')
                ->numeric()
                ->prefix('€')
                ->default(0.00),
            Forms\Components\Toggle::make('is_default')
                ->label('Προεπιλεγμένο'),
            Forms\Components\TextInput::make('sort_order')
                ->label('Σειρά')
                ->numeric()
                ->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Όνομα'),
                Tables\Columns\TextColumn::make('price_delta')->label('+€')->money('EUR'),
                Tables\Columns\IconColumn::make('is_default')->label('Default')->boolean(),
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
