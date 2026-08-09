<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\Catalog\Actions\CreateCategory as CreateCategoryAction;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;

class CreateCategory extends CreateRecord
{
    protected static string $resource = CategoryResource::class;

    /**
     * The domain action, not `Category::create()`.
     *
     * It derives a slug unique across the whole tree, appends the node within
     * its parent, and dispatches `CategoryCreated`. The action takes a name, a
     * parent and a team and nothing else, which is why the create form carries
     * nothing else — a description accepted by the form and dropped by the
     * action is a field that lies.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $parentId = $data['parent_category_id'] ?? null;

        return app(CreateCategoryAction::class)->handle(
            name: (string) $data['name'],
            parentId: $parentId === null || $parentId === '' ? null : (int) $parentId,
            teamId: PanelTeam::id(),
        );
    }
}
