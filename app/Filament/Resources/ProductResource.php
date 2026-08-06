<?php

namespace App\Filament\Resources;

use App\Actions\StoreProductImage;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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
            // The photo becomes a 600x600 WebP inside saveUploadedFileUsing,
            // before Filament records the path anywhere. That ordering is the
            // whole point: the component writes the returned path into both the
            // column and its own Livewire state, then checks on every hydration
            // that the file is still there. Converting afterwards — in a model
            // hook, as this once did — renames the file out from under a field
            // that is still pointing at the old name, and the field empties
            // itself and takes the photo with it on the next save.
            //
            // The resize options below are a bandwidth optimisation on top, not
            // the guarantee: they keep an 8 MB phone photo off the café's 4G.
            // `cover` does NOT force a square, it only decides how the image
            // fills the target box, so a portrait photo still arrives portrait
            // and the server-side crop is what squares it. The 1:1 editor is
            // what lets the owner choose *which* square, and its cropped output
            // is what gets uploaded — the original is reverted, not kept.
            Forms\Components\FileUpload::make('image')
                ->label('Φωτογραφία')
                ->helperText('Προαιρετικό — χωρίς φωτογραφία εμφανίζεται placeholder στο public menu.')
                ->image()
                // HEIC is the realistic rejection here, not a corrupt file: an
                // iPhone photo no browser will render, which would surface days
                // later as a blank card with no clue why. Better an immediate
                // error the owner can act on. JPEG and PNG are stored and
                // served as they are — only the WebP re-encode needs GD.
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->disk('public')
                ->directory('products')
                ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file, Forms\Components\FileUpload $component): ?string => app(StoreProductImage::class)->store($file, $component))
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
                // No "not converted yet" warning column here any more. A JPEG or
                // PNG is a perfectly good stored photo, so flagging one in red
                // reported a failure that had not happened.
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
