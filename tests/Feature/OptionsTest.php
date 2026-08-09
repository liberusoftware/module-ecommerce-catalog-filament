<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Catalog\Actions\SetProductOption;
use Liberu\Ecommerce\Catalog\Events\ProductOptionSet;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\EditProduct;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\OptionsRelationManager;
use Liberu\Ecommerce\Catalog\Models\Product;
use Livewire\Livewire;

function optionsFor(Product $product)
{
    return Livewire::test(OptionsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ]);
}

it('declares an axis through the domain action', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();

    Event::fake([ProductOptionSet::class]);

    optionsFor($product)
        ->callAction(TestAction::make('setOption')->table(), [
            'name' => 'Size',
            'values' => ['Small', 'Medium', 'Large'],
        ]);

    $option = $product->options()->firstOrFail();

    expect($option->name)->toBe('Size')
        ->and($option->values)->toBe(['Small', 'Medium', 'Large']);

    Event::assertDispatched(ProductOptionSet::class);
});

it('edits the axis it already has rather than adding a second one', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(SetProductOption::class)->handle($product, 'Size', ['Small']);

    // Keyed on the name, which is what makes a re-run of an import a no-op
    // instead of a unique-index error.
    optionsFor($product)
        ->callAction(TestAction::make('setOption')->table(), [
            'name' => 'Size',
            'values' => ['Small', 'Large'],
        ]);

    expect($product->options()->count())->toBe(1)
        ->and($product->options()->firstOrFail()->values)->toBe(['Small', 'Large']);
});

it('edits the choices on an axis without letting its name be changed', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    $option = app(SetProductOption::class)->handle($product, 'Colour', ['Olive']);

    optionsFor($product)
        ->mountAction(TestAction::make('setValues')->table($option))
        // The name is not a field. Editing it here would silently declare a new
        // axis and leave the old one behind, with the variants still pointing
        // at it.
        ->assertMountedActionModalDontSee('Axis')
        ->setActionData(['values' => ['Olive', 'Navy']])
        ->callMountedAction();

    expect($option->refresh()->values)->toBe(['Olive', 'Navy']);
});

it('folds duplicate choices away', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();

    optionsFor($product)
        ->callAction(TestAction::make('setOption')->table(), [
            'name' => 'Size',
            'values' => ['Small', 'Small', 'Large'],
        ]);

    // The domain de-duplicates and re-indexes: a JSON column with holes in its
    // keys decodes to an object rather than an array, and then every consumer
    // has to handle both shapes.
    expect($product->options()->firstOrFail()->values)->toBe(['Small', 'Large']);
});

it('lists the choices as text rather than as the word Array', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(SetProductOption::class)->handle($product, 'Size', ['Small', 'Large']);

    optionsFor($product)
        ->assertOk()
        ->assertSee('Small, Large');
});

/**
 * The absence is the point. `SetProductOption` is the only thing the domain
 * publishes for an option, so there is no delete to route through — and
 * deleting the row directly would leave variants carrying values on an axis
 * that no longer exists, having dispatched nothing.
 */
it('offers no way to delete an axis, because the domain publishes none', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    $option = app(SetProductOption::class)->handle($product, 'Size', ['Small']);

    optionsFor($product)
        ->assertActionDoesNotExist(TestAction::make('delete')->table($option));
});

it('offers no option writes on an archived product', function () {
    $this->actorForTeam(7);

    $archived = Product::factory()->ownedBy(7)->archived()->create();

    optionsFor($archived)
        ->assertActionHidden(TestAction::make('setOption')->table());
});
