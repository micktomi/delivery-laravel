<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Category;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationLabel = 'Προϊόντα';
    protected static ?string $modelLabel = 'Προϊόν';
    protected static ?string $pluralModelLabel = 'Προϊόντα';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('category_id')
                ->label('Κατηγορία')
                ->relationship('category', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Forms\Components\TextInput::make('name')
                ->label('Όνομα')
                ->required(),
            Forms\Components\Textarea::make('description')
                ->label('Περιγραφή')
                ->nullable()
                ->rows(2),
            // Resizing happens in the browser, so an 8 MB phone photo is a
            // ~800px square before it is ever uploaded. cover + equal target
            // dimensions is what actually forces 1:1; the editor alone does not.
            Forms\Components\FileUpload::make('image')
                ->label('Φωτογραφία')
                ->helperText('Προαιρετικό — χωρίς φωτογραφία μπαίνει η προεπιλεγμένη εικόνα.')
                ->image()
                ->disk('public')
                ->directory('products')
                ->imageEditor()
                ->imageEditorAspectRatios(['1:1'])
                ->imageResizeMode('cover')
                ->imageResizeTargetWidth(800)
                ->imageResizeTargetHeight(800)
                ->maxSize(8192),
            Forms\Components\TextInput::make('base_price')
                ->label('Βασική Τιμή (€)')
                ->numeric()
                ->prefix('€')
                ->required(),
            Forms\Components\Toggle::make('is_available')
                ->label('Διαθέσιμο')
                ->default(true),
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
                Tables\Columns\TextColumn::make('sort_order')->label('#')->sortable(),
                Tables\Columns\ImageColumn::make('image')
                    ->label('Εικόνα')
                    ->disk('public')
                    ->square(),
                Tables\Columns\TextColumn::make('category.name')->label('Κατηγορία')->sortable(),
                Tables\Columns\TextColumn::make('name')->label('Όνομα')->searchable(),
                Tables\Columns\TextColumn::make('base_price')->label('Τιμή')->money('EUR')->sortable(),
                Tables\Columns\ToggleColumn::make('is_available')->label('Διαθέσιμο'),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->label('Κατηγορία')
                    ->relationship('category', 'name'),
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
            RelationManagers\OptionGroupsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
