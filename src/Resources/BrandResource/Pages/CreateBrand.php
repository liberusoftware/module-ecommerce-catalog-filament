<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\Catalog\Actions\CreateBrand as CreateBrandAction;
use Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;

class CreateBrand extends CreateRecord
{
    protected static string $resource = BrandResource::class;

    /**
     * The domain action, not `Brand::create()`. It derives a unique slug and
     * dispatches `BrandCreated`; the team comes off the actor rather than the
     * form, because it is what `TaxonomyPolicy` authorizes against.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $name = (string) $data['name'];

        unset($data['name'], $data['team_id'], $data['slug']);

        return app(CreateBrandAction::class)->handle(
            name: $name,
            teamId: PanelTeam::id(),
            attributes: $data,
        );
    }
}
