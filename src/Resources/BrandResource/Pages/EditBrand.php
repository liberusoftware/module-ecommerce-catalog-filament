<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource;

/**
 * Descriptive fields, saved the ordinary way: the domain publishes no
 * `UpdateBrand`, and none of these carries a rule it owns. The slug is absent
 * because it is derived and addressable.
 */
class EditBrand extends EditRecord
{
    protected static string $resource = BrandResource::class;

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
