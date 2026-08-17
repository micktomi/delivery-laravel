<?php

namespace App\Filament\Resources;

use App\Actions\CancelOrder;
use App\Actions\TransitionOrderStatus;
use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Models\OrderItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    // Public tracking uses an unguessable token; admin URLs stay on the id.
    protected static ?string $recordRouteKeyName = 'id';

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
                Tables\Columns\TextColumn::make('coupon_code')
                    ->label('Κουπόνι')
                    ->placeholder('—'),
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
                // Defaults to today so the resource opens on the same view the
                // old /kitchen/history page showed; any date can be selected,
                // or cleared for all orders. The header stats widget above the
                // table has its own independent date field — deliberately not
                // wired to this filter, see DailyOrderStatsWidget's docblock.
                Tables\Filters\Filter::make('created_at')
                    ->label('Ημερομηνία')
                    ->form([
                        Forms\Components\DatePicker::make('date')
                            ->label('Ημερομηνία')
                            ->default(fn () => today()->toDateString()),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['date'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->whereDate('created_at', $date),
                    ))
                    ->indicateUsing(fn (array $data): ?string => filled($data['date'] ?? null)
                        ? 'Ημερομηνία: '.Carbon::parse($data['date'])->format('d/m/Y')
                        : null),
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
                            // The status this row was rendered with guards against
                            // acting on a table that another device already moved on.
                            app(TransitionOrderStatus::class)->execute($record, $record->status);
                            Notification::make()->title('Κατάσταση ενημερώθηκε')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title($e->validator->errors()->first())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('cancel')
                    ->label('Ακύρωση')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Ακύρωση παραγγελίας')
                    ->modalDescription('Η ακύρωση είναι οριστική και η παραγγελία δεν προσμετράται στον τζίρο.')
                    ->visible(fn (Order $record) => ! in_array(
                        $record->status,
                        [OrderStatus::Completed, OrderStatus::Cancelled],
                        true,
                    ))
                    ->action(function (Order $record) {
                        try {
                            app(CancelOrder::class)->execute($record);
                            Notification::make()->title('Η παραγγελία ακυρώθηκε')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title($e->validator->errors()->first())->danger()->send();
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
                    ->formatStateUsing(fn ($state) => '#'.str_pad($state, 3, '0', STR_PAD_LEFT)),
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
                        ->formatStateUsing(fn ($state, $record) => $record->quantity.'× '.$state),
                    Infolists\Components\TextEntry::make('selected_options')
                        ->label('Επιλογές')
                        ->state(function (OrderItem $record): string {
                            if (empty($record->selected_options)) {
                                return '-';
                            }

                            return collect($record->selected_options)
                                ->map(function (array $option): string {
                                    $value = $option['value'] ?? '-';
                                    $group = $option['group'] ?? null;

                                    return filled($group) ? $group.': '.$value : $value;
                                })
                                ->implode(' · ');
                        }),
                    Infolists\Components\TextEntry::make('line_total')->label('Τιμή')->money('EUR'),
                ])->columns(3),
            ]),

            // Snapshots, not a live read of the coupon: these are the figures
            // the courier collected on, whatever the coupon says today.
            Infolists\Components\Section::make('Σύνολα')->schema([
                Infolists\Components\TextEntry::make('subtotal')->label('Υποσύνολο')->money('EUR'),
                Infolists\Components\TextEntry::make('coupon_code')
                    ->label('Κουπόνι')
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('discount_amount')
                    ->label('Έκπτωση')
                    ->money('EUR')
                    ->color(fn (Order $record) => $record->hasDiscount() ? 'success' : null),
                Infolists\Components\TextEntry::make('delivery_fee')->label('Μεταφορικά')->money('EUR'),
                Infolists\Components\TextEntry::make('total')->label('Σύνολο')->money('EUR')->weight('bold'),
            ])->columns(3),
        ]);
    }

    /**
     * Admin record pages bind orders by their numeric id. Table actions pass
     * the model itself to getUrl(), but Order's public route key is its token;
     * normalize only the admin record parameter before Laravel generates it.
     */
    public static function getUrl(
        string $name = 'index',
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
    ): string {
        if (($parameters['record'] ?? null) instanceof Order) {
            $parameters['record'] = $parameters['record']->getKey();
        }

        return parent::getUrl($name, $parameters, $isAbsolute, $panel, $tenant);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
