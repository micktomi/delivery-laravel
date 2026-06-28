<?php

namespace App\Filament\Resources;

use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationLabel = 'Παραγγελίες';
    protected static ?string $modelLabel = 'Παραγγελία';
    protected static ?string $pluralModelLabel = 'Παραγγελίες';
    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('customer_name')->label('Όνομα'),
            Forms\Components\TextInput::make('phone')->label('Τηλέφωνο'),
            Forms\Components\TextInput::make('address')->label('Διεύθυνση'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_number')
                    ->label('#')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => str_pad($state, 3, '0', STR_PAD_LEFT)),
                Tables\Columns\TextColumn::make('customer_name')->label('Πελάτης')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('Τηλ.'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge(),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Πληρωμή')
                    ->badge(),
                Tables\Columns\TextColumn::make('total')->label('Σύνολο')->money('EUR'),
                Tables\Columns\TextColumn::make('placed_at')
                    ->label('Ώρα')
                    ->dateTime('d/m H:i')
                    ->sortable(),
            ])
            ->defaultSort('placed_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Κατάσταση')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(
                        fn ($case) => [$case->value => $case->getLabel()]
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('advance')
                    ->label('Επόμενο βήμα')
                    ->icon('heroicon-o-arrow-right')
                    ->color('success')
                    ->visible(fn (Order $record) => $record->status->nextStatus() !== null)
                    ->action(function (Order $record) {
                        try {
                            app(TransitionOrderStatus::class)->execute($record);
                            Notification::make()->title('Κατάσταση ενημερώθηκε')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Στοιχεία παραγγελίας')->schema([
                Infolists\Components\TextEntry::make('display_number')
                    ->label('#')
                    ->formatStateUsing(fn ($state) => '#' . str_pad($state, 3, '0', STR_PAD_LEFT)),
                Infolists\Components\TextEntry::make('status')->label('Κατάσταση')->badge(),
                Infolists\Components\TextEntry::make('payment_method')->label('Πληρωμή')->badge(),
                Infolists\Components\TextEntry::make('placed_at')->label('Ώρα')->dateTime('d/m/Y H:i'),
            ])->columns(4),

            Infolists\Components\Section::make('Πελάτης')->schema([
                Infolists\Components\TextEntry::make('customer_name')->label('Όνομα'),
                Infolists\Components\TextEntry::make('phone')->label('Τηλέφωνο'),
                Infolists\Components\TextEntry::make('address')->label('Διεύθυνση'),
                Infolists\Components\TextEntry::make('floor_bell')->label('Όροφος/Κουδούνι'),
                Infolists\Components\TextEntry::make('notes')->label('Σημειώσεις'),
            ])->columns(2),

            Infolists\Components\Section::make('Είδη')->schema([
                Infolists\Components\RepeatableEntry::make('items')->label('')->schema([
                    Infolists\Components\TextEntry::make('product_name')
                        ->label('Προϊόν')
                        ->formatStateUsing(fn ($state, $record) => $record->quantity . '× ' . $state),
                    Infolists\Components\TextEntry::make('selected_options')
                        ->label('Επιλογές')
                        ->formatStateUsing(function ($state) {
                            if (empty($state)) {
                                return '-';
                            }
                            return collect($state)
                                ->map(fn ($opt) => $opt['group'] . ': ' . $opt['value'])
                                ->implode(' · ');
                        }),
                    Infolists\Components\TextEntry::make('line_total')->label('Τιμή')->money('EUR'),
                ])->columns(3),
            ]),

            Infolists\Components\Section::make('Σύνολα')->schema([
                Infolists\Components\TextEntry::make('subtotal')->label('Υποσύνολο')->money('EUR'),
                Infolists\Components\TextEntry::make('delivery_fee')->label('Μεταφορικά')->money('EUR'),
                Infolists\Components\TextEntry::make('total')->label('Σύνολο')->money('EUR'),
            ])->columns(3),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
