<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource;

/**
 * The descriptive attributes are saved the ordinary way; the domain publishes
 * no `UpdateCategory` and a name, a description, an image and a sort position
 * carry no invariant beyond the form's own validation. The two fields that do
 * — the slug and the parent — are absent, and the parent has its own action.
 */
class EditCategory extends EditRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CategoryResource::moveAction(),
            DeleteAction::make(),
        ];
    }
}
