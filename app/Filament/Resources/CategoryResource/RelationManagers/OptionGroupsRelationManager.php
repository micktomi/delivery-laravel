<?php

namespace App\Filament\Resources\CategoryResource\RelationManagers;

use App\Enums\SelectionType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class OptionGroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'optionGroups';

    protected static ?string $title = 'Πρότυπο Ομάδων Επιλογών';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Όνομα')
                ->required(),
            Forms\Components\Select::make('selection')
                ->label('Τύπος')
                ->options([
                    SelectionType::Single->value => 'Μονή επιλογή',
                    SelectionType::Multi->value => 'Πολλαπλή επιλογή',
                ])
                ->required(),
            Forms\Components\Toggle::make('is_required')->label('Υποχρεωτική'),
            Forms\Components\TextInput::make('sort_order')->label('Σειρά')->numeric()->default(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Όνομα'),
                Tables\Columns\TextColumn::make('selection')->label('Τύπος')
                    ->formatStateUsing(fn ($state) => $state instanceof SelectionType ? $state->value : $state),
                Tables\Columns\IconColumn::make('is_required')->label('Υποχρεωτική')->boolean(),
                Tables\Columns\TextColumn::make('pivot.sort_order')->label('Σειρά'),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect()
                    ->form(fn (Tables\Actions\AttachAction $action) => [
                        $action->getRecordSelect(),
                        Forms\Components\TextInput::make('sort_order')->label('Σειρά')->numeric()->default(0),
                    ]),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make(),
            ]);
    }
}
