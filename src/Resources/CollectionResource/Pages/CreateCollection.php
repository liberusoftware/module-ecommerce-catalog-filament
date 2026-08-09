<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\Catalog\Actions\CreateCollection as CreateCollectionAction;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;

class CreateCollection extends CreateRecord
{
    protected static string $resource = CollectionResource::class;

    /**
     * The domain action, not `ProductCollection::create()`. It derives a unique
     * slug and dispatches `CollectionCreated`; the team comes off the actor
     * rather than the form, because it is what `TaxonomyPolicy` authorizes
     * against.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $name = (string) $data['name'];

        unset($data['name'], $data['team_id'], $data['slug']);

        return app(CreateCollectionAction::class)->handle(
            name: $name,
            teamId: PanelTeam::id(),
            attributes: $data,
        );
    }
}
