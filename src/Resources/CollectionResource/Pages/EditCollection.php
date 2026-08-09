<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource;

/**
 * Descriptive fields, saved the ordinary way: the domain publishes no
 * `UpdateCollection`, and none of these carries a rule it owns. The slug is
 * absent because it is derived and addressable.
 */
class EditCollection extends EditRecord
{
    protected static string $resource = CollectionResource::class;

    /**
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
