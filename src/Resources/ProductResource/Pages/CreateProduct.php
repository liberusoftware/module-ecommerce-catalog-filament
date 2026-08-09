<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\Catalog\Actions\CreateProduct as CreateProductAction;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * The domain action, not `Product::create()`.
     *
     * It derives the unique slug, starts the product in `draft` and `hidden`,
     * and dispatches `ProductCreated`. A form that inserted the row itself would
     * have to know all three, and would be the second place each of them lives.
     *
     * `name`, `team_id` and `store_id` are lifted out of the attribute spread
     * because the action takes them as named arguments — deliberately, so that
     * who owns the row cannot be decided by whatever happens to be in the array.
     * The team comes from the actor rather than from the form: it is the key
     * `ProductPolicy` authorizes against, and a product created into a team the
     * operator does not work in is one they immediately cannot edit.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $name = (string) $data['name'];
        $storeId = $data['store_id'] ?? null;

        unset($data['name'], $data['store_id'], $data['team_id'], $data['slug'], $data['status'], $data['visibility']);

        return app(CreateProductAction::class)->handle(
            name: $name,
            teamId: PanelTeam::id(),
            storeId: $storeId === null || $storeId === '' ? null : (int) $storeId,
            attributes: $data,
        );
    }
}
