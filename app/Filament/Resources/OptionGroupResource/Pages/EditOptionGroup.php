<?php

namespace App\Filament\Resources\OptionGroupResource\Pages;

use App\Filament\Resources\OptionGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOptionGroup extends EditRecord
{
    protected static string $resource = OptionGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
