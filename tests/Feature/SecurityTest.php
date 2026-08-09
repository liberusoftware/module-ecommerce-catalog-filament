<?php

use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\Catalog\Actions\AddVariant;
use Liberu\Ecommerce\Catalog\Actions\PublishToChannel;
use Liberu\Ecommerce\Catalog\Enums\ProductStatus;
use Liberu\Ecommerce\Catalog\Enums\Visibility;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\CreateProduct;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\EditProduct;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\ListProducts;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\CollectionsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\OptionsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\PublicationsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\TagsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\PackageTestbench\TestUser;
use Livewire\Livewire;

it('answers no team rather than every team when nobody is signed in', function () {
    expect(PanelTeam::id())->toBeNull()
        ->and(ProductResource::getEloquentQuery()->count())->toBe(0);
});

it('refuses an unowned row to the team that can see it in no list', function () {
    $this->actorForTeam(7);

    $orphan = Product::factory()->create();

    // Deliberately stricter than a read scope would have to be. Seeing an
    // orphan is how it gets fixed; editing one is how it gets stolen — and this
    // package does not even list them, because a row whose every action refuses
    // reads as a broken panel rather than a denied one.
    expect(ProductResource::getEloquentQuery()->count())->toBe(0);

    Livewire::test(ListProducts::class)
        ->assertCanNotSeeTableRecords([$orphan]);
});

it('never lists the orphan rows a null team id would match', function () {
    $this->actingAs(TestUser::factory()->create());

    Product::factory()->create();
    Product::factory()->create();

    // `where('team_id', null)` compiles to `is null`, so an actor with no team
    // would be shown precisely the rows nobody may touch. The guard is an
    // explicit `whereRaw('1 = 0')`.
    expect(ProductResource::getEloquentQuery()->count())->toBe(0);
});

it('keeps every relation manager away from another team\'s product', function () {
    $this->actorForTeam(7);

    $theirs = Product::factory()->ownedBy(9)->create();

    $managers = [
        VariantsRelationManager::class,
        OptionsRelationManager::class,
        TagsRelationManager::class,
        CollectionsRelationManager::class,
        PublicationsRelationManager::class,
    ];

    foreach ($managers as $manager) {
        expect($manager::canViewForRecord($theirs, EditProduct::class))->toBeFalse($manager);
    }
});

it('writes the actor\'s team onto a new product, whatever the form says', function () {
    $this->actorForTeam(7);

    // `team_id` is not a form field and `CreateProduct` takes it as a named
    // argument rather than in the attribute spread, so there is no path by which
    // a crafted payload decides who owns the row.
    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Waxed Jacket'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->firstOrFail()->team_id)->toBe(7);
});

it('starts a new product not for sale and not reachable, whatever the form says', function () {
    $this->actorForTeam(7);

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Waxed Jacket'])
        ->call('create');

    // The moment a product becomes visible should be a decision somebody made
    // rather than a side effect of a row appearing.
    $product = Product::query()->firstOrFail();

    expect($product->status)->toBe(ProductStatus::Draft)
        ->and($product->visibility)->toBe(Visibility::Hidden);
});

it('will not move a product to a status the transition table forbids', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();

    // Active to draft is absent from the enum entirely: a product that has been
    // offered has been seen and linked, and un-finishing it is how a live URL
    // starts 404ing. Submitting it anyway must change nothing and must not 500.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->callAction('changeStatus', ['status' => ProductStatus::Draft->value]);

    expect($product->refresh()->status)->toBe(ProductStatus::Active);
});

it('offers no lifecycle move at all once a product is archived', function () {
    $this->actorForTeam(7);

    $archived = Product::factory()->ownedBy(7)->archived()->create();

    // `changeStatus` refuses a terminal state, so the action is not offered
    // rather than offered with an empty select.
    Livewire::test(ListProducts::class)
        ->assertActionHidden(TestAction::make('changeStatus')->table($archived));
});

it('keeps a variant belonging to another team out of reach', function () {
    $this->actorForTeam(7);

    $theirs = Product::factory()->ownedBy(9)->create();
    app(AddVariant::class)->handle($theirs, 'THEIRS-001');

    // The relation manager refuses the whole panel rather than each row, which
    // is the check that also covers the actions a row does not carry.
    expect(VariantsRelationManager::canViewForRecord($theirs, EditProduct::class))->toBeFalse();
});

it('separates publishing from editing, as the domain does', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(PublishToChannel::class)->handle($product, 3);

    // `publish` is a distinct ability so that a host wanting a second pair of
    // eyes on it has somewhere to say so. Today it answers the same as
    // `update`, and this asserts the surface asks for it rather than for
    // whichever ability happened to be nearest.
    Livewire::test(PublicationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->assertActionVisible(TestAction::make('publish')->table());

    $archived = Product::factory()->ownedBy(7)->archived()->create();

    Livewire::test(PublicationsRelationManager::class, [
        'ownerRecord' => $archived,
        'pageClass' => EditProduct::class,
    ])
        ->assertActionHidden(TestAction::make('publish')->table());
});
