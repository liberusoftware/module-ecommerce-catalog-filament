<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\Catalog\Actions\CreateVendor as CreateVendorAction;
use Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;

class CreateVendor extends CreateRecord
{
    protected static string $resource = VendorResource::class;

    /**
     * The domain action, not `Vendor::create()`. It derives a unique slug and
     * dispatches `VendorCreated`; the team comes off the actor rather than the
     * form, because it is what `TaxonomyPolicy` authorizes against.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $name = (string) $data['name'];

        unset($data['name'], $data['team_id'], $data['slug']);

        return app(CreateVendorAction::class)->handle(
            name: $name,
            teamId: PanelTeam::id(),
            attributes: $data,
        );
    }
}
