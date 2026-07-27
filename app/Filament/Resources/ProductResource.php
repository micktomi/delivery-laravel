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
            // Everything here is a bandwidth optimisation, not a guarantee: it
            // keeps an 8 MB phone photo from going over the café's 4G, and
            // that is all. `cover` does NOT force a square — it only decides
            // how the image fills the target box, and a portrait photo still
            // arrives portrait. What is actually stored is decided server-side
            // by EncodeProductImage: centre-cropped to a 600x600 WebP.
            //
            // The 1:1 editor stays because it lets the owner pick WHICH square
            // when the automatic centre crop gets it wrong.
            Forms\Components\FileUpload::make('image')
                ->label('Φωτογραφία')
                ->helperText('Προαιρετικό — εμφανίζεται μόνο όταν όλα τα προϊόντα της κατηγορίας έχουν φωτογραφία.')
                ->image()
                // HEIC is the realistic rejection here, not a corrupt file: an
                // iPhone photo GD cannot convert and browsers will not render,
                // which would surface days later as a blank card with no clue
                // why. Better an immediate error the owner can act on.
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->disk('public')
                ->directory('products')
                ->imageEditor()
                ->imageEditorAspectRatios(['1:1'])
                ->imageResizeMode('cover')
                ->imageResizeTargetWidth(600)
                ->imageResizeTargetHeight(600)
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
                // A photo that survived upload without being converted is a
                // real, if rare, failure — GD missing on the server, or a
                // format it could not decode. Without this the only trace is a
                // log line nobody in a café will ever read.
                Tables\Columns\IconColumn::make('image_conversion')
                    ->label('')
                    ->state(fn (Product $record): bool => static::imageNeedsAttention($record))
                    ->icon(fn (bool $state): ?string => $state ? 'heroicon-o-exclamation-triangle' : null)
                    ->color('danger')
                    ->tooltip(fn (bool $state): ?string => $state
                        ? 'Η φωτογραφία δεν μετατράπηκε σε WebP. Ανέβασέ την ξανά — αν επιμείνει, λείπει η επέκταση GD από τον server.'
                        : null),
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

    /**
     * A product with no photo is fine and flags nothing; the storefront simply
     * renders no image. Only an uploaded file that is not WebP means the
     * server-side re-encode did not happen.
     */
    public static function imageNeedsAttention(Product $record): bool
    {
        return filled($record->image)
            && ! str_ends_with(strtolower($record->image), '.webp');
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
