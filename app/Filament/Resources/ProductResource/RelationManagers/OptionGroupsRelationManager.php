<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class OptionGroupsRelationManager extends RelationManager
{
    protected static string $relationship = 'optionGroups';
    protected static ?string $title = 'Ομάδες Επιλογών (Override)';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Όνομα')->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Όνομα'),
                Tables\Columns\TextColumn::make('selection')->label('Τύπος')
                    ->formatStateUsing(fn ($state) => $state instanceof \App\Enums\SelectionType ? $state->value : $state),
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
                Tables\Actions\Action::make('resetToDefaults')
                    ->label('Επαναφορά από Κατηγορία')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function () {
                        $product = $this->getOwnerRecord();
                        $product->load('category.optionGroups');

                        $groups = $product->category->optionGroups;

                        $syncData = $groups->mapWithKeys(fn ($group) => [
                            $group->id => ['sort_order' => $group->pivot->sort_order],
                        ])->all();

                        $product->optionGroups()->sync($syncData);

                        Notification::make()
                            ->title('Επαναφορά ολοκληρώθηκε')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make(),
            ]);
    }
}
