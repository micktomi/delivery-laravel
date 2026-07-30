<?php

namespace App\Filament\Resources;

use App\Enums\CouponType;
use App\Filament\Resources\CouponResource\Pages;
use App\Models\Coupon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'Κουπόνια';

    protected static ?string $modelLabel = 'Κουπόνι';

    protected static ?string $pluralModelLabel = 'Κουπόνια';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Uppercased in the field itself, not only on save, so the
            // uniqueness check compares what will actually be stored:
            // welcome15 and WELCOME15 are one coupon.
            Forms\Components\TextInput::make('code')
                ->label('Κωδικός')
                ->helperText('Ο κωδικός που δίνετε στο ταμείο. Αποθηκεύεται με κεφαλαία.')
                ->required()
                ->maxLength(50)
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('code', Coupon::normalizeCode($state)))
                ->dehydrateStateUsing(fn ($state) => Coupon::normalizeCode($state))
                ->unique(ignoreRecord: true),

            Forms\Components\Select::make('type')
                ->label('Τύπος')
                ->options(collect(CouponType::cases())->mapWithKeys(
                    fn (CouponType $case) => [$case->value => $case->getLabel()]
                ))
                ->default(CouponType::Percentage->value)
                ->required()
                ->live(),

            // A percentage over 100 would hand money back with the order.
            Forms\Components\TextInput::make('value')
                ->label('Αξία')
                ->numeric()
                ->required()
                ->minValue(0)
                ->maxValue(fn (Forms\Get $get) => $get('type') === CouponType::Percentage->value ? 100 : null)
                ->suffix(fn (Forms\Get $get) => $get('type') === CouponType::Percentage->value ? '%' : '€')
                ->helperText(fn (Forms\Get $get) => $get('type') === CouponType::Percentage->value
                    ? 'Ποσοστό έκπτωσης, 0 έως 100.'
                    : 'Ευρώ έκπτωσης. Σε μικρότερο καλάθι κόβεται στο ύψος του καλαθιού.'),

            Forms\Components\TextInput::make('min_order_total')
                ->label('Ελάχιστο υποσύνολο')
                ->helperText('Κενό = χωρίς ελάχιστο. Ελέγχεται πριν την έκπτωση.')
                ->numeric()
                ->minValue(0)
                ->prefix('€')
                ->nullable(),

            Forms\Components\DateTimePicker::make('starts_at')
                ->label('Ισχύει από')
                ->helperText('Κενό = ισχύει αμέσως.')
                ->seconds(false)
                ->nullable(),

            Forms\Components\DateTimePicker::make('expires_at')
                ->label('Λήγει')
                ->helperText('Κενό = χωρίς λήξη.')
                ->seconds(false)
                ->after('starts_at')
                ->nullable(),

            Forms\Components\TextInput::make('max_uses')
                ->label('Μέγιστες χρήσεις')
                ->helperText('Σύνολο για όλους τους πελάτες. Κενό = απεριόριστες.')
                ->numeric()
                ->minValue(1)
                ->nullable(),

            // Never editable: it moves only when an order claims it.
            Forms\Components\Placeholder::make('used_count')
                ->label('Χρήσεις μέχρι τώρα')
                ->content(fn (?Coupon $record) => $record ? self::usageLabel($record) : '—')
                ->visibleOn('edit'),

            Forms\Components\Toggle::make('is_active')
                ->label('Ενεργό')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('Κωδικός')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Τύπος')
                    ->badge(),

                Tables\Columns\TextColumn::make('value')
                    ->label('Αξία')
                    ->formatStateUsing(fn ($state, Coupon $record) => $record->type === CouponType::Percentage
                        ? rtrim(rtrim(number_format((float) $state, 2, ',', '.'), '0'), ',').'%'
                        : number_format((float) $state, 2, ',', '.').' €'),

                // The column the owner watches: a code about to run out.
                Tables\Columns\TextColumn::make('used_count')
                    ->label('Χρήσεις')
                    ->formatStateUsing(fn (Coupon $record) => self::usageLabel($record))
                    ->color(fn (Coupon $record) => $record->max_uses !== null
                        && $record->used_count >= $record->max_uses ? 'danger' : null)
                    ->sortable(),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Λήγει')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Ενεργό')
                    ->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Ενεργό'),
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

    private static function usageLabel(Coupon $coupon): string
    {
        return $coupon->used_count.' / '.($coupon->max_uses ?? '∞');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCoupons::route('/'),
            'create' => Pages\CreateCoupon::route('/create'),
            'edit' => Pages\EditCoupon::route('/{record}/edit'),
        ];
    }
}
