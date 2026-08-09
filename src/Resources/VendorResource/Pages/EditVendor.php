<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource;

/**
 * Descriptive fields, saved the ordinary way: the domain publishes no
 * `UpdateVendor`, and none of these carries a rule it owns. The slug is absent
 * because it is derived and addressable.
 */
class EditVendor extends EditRecord
{
    protected static string $resource = VendorResource::class;

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
