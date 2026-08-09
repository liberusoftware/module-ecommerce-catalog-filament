<?php

namespace Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource;
use Liberu\Ecommerce\Catalog\Filament\Support\Reachability;
use Liberu\Ecommerce\Catalog\Models\Product;

/**
 * The descriptive attributes are saved the ordinary way, because the domain
 * deliberately publishes no action for them: a name, a description and some
 * meta tags carry no invariant beyond the field validation the form already
 * states. Everything that does carry one — the lifecycle, the visibility, the
 * effective dates, the variants, the options, the tags, the collections and the
 * publications — is reached through an action, from the header here or from a
 * relation manager below.
 */
class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Whether a shopper can see this product, in a sentence, above the form.
     *
     * The three facts each have their own action in the header, and an operator
     * reading three separate controls still has to work out what they add up
     * to. This says it, and when the answer is no it names the reason.
     */
    public function getSubheading(): ?string
    {
        /** @var Product $record */
        $record = $this->getRecord();

        // One page, one record: an explicit load here is a second query, where
        // the same relation left unloaded would make the channel note silently
        // disappear from a page that has room to say it.
        $record->loadMissing('publications');

        return Reachability::of($record)->summary();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ProductResource::changeStatusAction(),
            ProductResource::setVisibilityAction(),
            ProductResource::scheduleAvailabilityAction(),
            DeleteAction::make(),
        ];
    }
}
