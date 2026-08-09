<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource;

class ListVendors extends ListRecords
{
    protected static string $resource = VendorResource::class;

    /**
     * @return array<int, CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
