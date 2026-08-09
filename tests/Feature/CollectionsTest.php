<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Catalog\Actions\AddProductToCollection;
use Liberu\Ecommerce\Catalog\Events\ProductAddedToCollection;
use Liberu\Ecommerce\Catalog\Events\ProductRemovedFromCollection;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource\Pages\EditCollection;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource\RelationManagers\ProductsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\EditProduct;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\CollectionsRelationManager;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\Ecommerce\Catalog\Models\ProductCollection;
use Livewire\Livewire;

function productsOf(ProductCollection $collection)
{
    return Livewire::test(ProductsRelationManager::class, [
        'ownerRecord' => $collection,
        'pageClass' => EditCollection::class,
    ]);
}

function collectionsOf(Product $product)
{
    return Livewire::test(CollectionsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ]);
}

it('puts a product in a collection from the collection, through the domain action', function () {
    $this->actorForTeam(7);

    $collection = ProductCollection::factory()->ownedBy(7)->create();
    $product = Product::factory()->ownedBy(7)->create();

    Event::fake([ProductAddedToCollection::class]);

    productsOf($collection)
        ->callAction(TestAction::make('addProduct')->table(), ['product' => $product->id]);

    expect($collection->products()->count())->toBe(1);

    Event::assertDispatched(ProductAddedToCollection::class);
});

it('puts a product in a collection from the product, through the same action', function () {
    $this->actorForTeam(7);

    $collection = ProductCollection::factory()->ownedBy(7)->create();
    $product = Product::factory()->ownedBy(7)->create();

    collectionsOf($product)
        ->callAction(TestAction::make('addToCollection')->table(), ['collection' => $collection->id]);

    expect($product->collections()->count())->toBe(1);
});

it('appends rather than asking the merchant what is already there', function () {
    $this->actorForTeam(7);

    $collection = ProductCollection::factory()->ownedBy(7)->create();
    $first = Product::factory()->ownedBy(7)->create();
    $second = Product::factory()->ownedBy(7)->create();

    app(AddProductToCollection::class)->handle($collection, $first);

    productsOf($collection)
        ->callAction(TestAction::make('addProduct')->table(), ['product' => $second->id]);

    expect((int) $collection->products()->where('product_id', $second->id)->firstOrFail()->pivot->position)->toBe(2);
});

it('honours a position a merchant does state', function () {
    $this->actorForTeam(7);

    $collection = ProductCollection::factory()->ownedBy(7)->create();
    $product = Product::factory()->ownedBy(7)->create();

    productsOf($collection)
        ->callAction(TestAction::make('addProduct')->table(), [
            'product' => $product->id,
            'position' => 9,
        ]);

    expect((int) $collection->products()->firstOrFail()->pivot->position)->toBe(9);
});

it('adding the same product twice is not an incident', function () {
    $this->actorForTeam(7);

    $collection = ProductCollection::factory()->ownedBy(7)->create();
    $product = Product::factory()->ownedBy(7)->create();

    app(AddProductToCollection::class)->handle($collection, $product);

    // The unique index would refuse a second `attach`. The domain uses
    // `syncWithoutDetaching`, so a double-click is a no-op rather than a stack
    // trace.
    productsOf($collection)
        ->callAction(TestAction::make('addProduct')->table(), ['product' => $product->id]);

    expect($collection->products()->count())->toBe(1);
});

it('takes a product out of a collection through the domain action', function () {
    $this->actorForTeam(7);

    $collection = ProductCollection::factory()->ownedBy(7)->create();
    $product = Product::factory()->ownedBy(7)->create();
    app(AddProductToCollection::class)->handle($collection, $product);

    Event::fake([ProductRemovedFromCollection::class]);

    productsOf($collection)
        ->callAction(TestAction::make('removeProduct')->table($product));

    expect($collection->products()->count())->toBe(0);

    Event::assertDispatched(ProductRemovedFromCollection::class);
});

it('says on the collection whether a shopper can see what is in it', function () {
    $this->actorForTeam(7);

    $collection = ProductCollection::factory()->ownedBy(7)->create();
    $draft = Product::factory()->ownedBy(7)->draft()->create(['name' => 'Unfinished Coat']);
    app(AddProductToCollection::class)->handle($collection, $draft);

    // A merchant building a campaign collection wants to know which of the
    // things they just put in it a shopper can actually see.
    productsOf($collection)
        ->assertOk()
        ->assertSee('Not visible')
        ->assertSee('its status is Draft rather than Active');
});

it('offers no collection writes to a team that does not own the collection', function () {
    $this->actorForTeam(7);

    expect(ProductsRelationManager::canViewForRecord(ProductCollection::factory()->ownedBy(9)->create(), EditCollection::class))->toBeFalse()
        ->and(ProductsRelationManager::canViewForRecord(ProductCollection::factory()->create(), EditCollection::class))->toBeFalse()
        ->and(ProductsRelationManager::canViewForRecord(ProductCollection::factory()->ownedBy(7)->create(), EditCollection::class))->toBeTrue();
});
