<?php

namespace App\Filament\Resources\CategoryResource\Pages;

use App\Filament\Resources\CategoryResource;
use App\Models\Category;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // See CategoryResource::table() for why this check exists:
            // products.category_id is restrict-on-delete, so without this the
            // operator only finds out from a raw query-exception page.
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action, Category $record) {
                    if ($record->products()->exists()) {
                        Notification::make()
                            ->title('Δεν είναι δυνατή η διαγραφή')
                            ->body('Η κατηγορία «'.$record->name.'» έχει προϊόντα. Μετακινήστε ή διαγράψτε πρώτα τα προϊόντα της.')
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
        ];
    }
}
