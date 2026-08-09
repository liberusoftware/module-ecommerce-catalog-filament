<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Catalog\Events\BrandCreated;
use Liberu\Ecommerce\Catalog\Events\CategoryCreated;
use Liberu\Ecommerce\Catalog\Events\CategoryMoved;
use Liberu\Ecommerce\Catalog\Events\CollectionCreated;
use Liberu\Ecommerce\Catalog\Events\VendorCreated;
use Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource\Pages\CreateBrand;
use Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource\Pages\EditBrand;
use Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource\Pages\ListBrands;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource\Pages\CreateCategory;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource\Pages\EditCategory;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource\Pages\ListCategories;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource\Pages\CreateCollection;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource\Pages\EditCollection;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource\Pages\ListCollections;
use Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource\Pages\CreateVendor;
use Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource\Pages\EditVendor;
use Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource\Pages\ListVendors;
use Liberu\Ecommerce\Catalog\Models\Brand;
use Liberu\Ecommerce\Catalog\Models\Category;
use Liberu\Ecommerce\Catalog\Models\ProductCollection;
use Liberu\Ecommerce\Catalog\Models\Vendor;
use Liberu\PackageTestbench\TestUser;
use Livewire\Livewire;

it('creates a category through the domain action', function () {
    $this->actorForTeam(7);

    Event::fake([CategoryCreated::class]);

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Outerwear'])
        ->call('create')
        ->assertHasNoFormErrors();

    $category = Category::query()->firstOrFail();

    expect($category->slug)->toBe('outerwear')
        ->and($category->team_id)->toBe(7)
        ->and($category->parent_category_id)->toBeNull();

    Event::assertDispatched(CategoryCreated::class);
});

it('files a new category under a parent', function () {
    $this->actorForTeam(7);

    $parent = Category::factory()->ownedBy(7)->create(['name' => 'Outerwear']);

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Parkas', 'parent_category_id' => $parent->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Category::query()->where('name', 'Parkas')->firstOrFail()->parent_category_id)->toBe($parent->id);
});

it('carries on its create form only what the create action accepts', function () {
    $this->actorForTeam(7);

    // `CreateCategory` takes a name, a parent and a team. A description typed
    // into the create form would be accepted by the form and dropped by the
    // action, which is a field that lies.
    Livewire::test(CreateCategory::class)
        ->assertFormFieldExists('name')
        ->assertFormFieldExists('parent_category_id')
        ->assertFormFieldDoesNotExist('description')
        ->assertFormFieldDoesNotExist('slug');
});

it('offers the descriptive fields once the category exists, and never the parent', function () {
    $this->actorForTeam(7);

    $category = Category::factory()->ownedBy(7)->create();

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->assertFormFieldExists('description')
        // Moving a node is not an attribute change — it is `MoveCategory`, which
        // is the only thing that refuses a cycle.
        ->assertFormFieldDoesNotExist('parent_category_id')
        ->assertFormFieldDoesNotExist('slug');
});

it('re-parents a category through the domain action', function () {
    $this->actorForTeam(7);

    $outerwear = Category::factory()->ownedBy(7)->create();
    $parkas = Category::factory()->ownedBy(7)->create();

    Event::fake([CategoryMoved::class]);

    Livewire::test(EditCategory::class, ['record' => $parkas->getRouteKey()])
        ->callAction('move', ['parent_category_id' => $outerwear->id]);

    expect($parkas->refresh()->parent_category_id)->toBe($outerwear->id);

    Event::assertDispatched(CategoryMoved::class);
});

it('promotes a category to a root by leaving the parent empty', function () {
    $this->actorForTeam(7);

    $outerwear = Category::factory()->ownedBy(7)->create();
    $parkas = Category::factory()->ownedBy(7)->under($outerwear)->create();

    Livewire::test(EditCategory::class, ['record' => $parkas->getRouteKey()])
        ->callAction('move', ['parent_category_id' => null]);

    expect($parkas->refresh()->parent_category_id)->toBeNull();
});

it('will not move a category under its own descendant', function () {
    $this->actorForTeam(7);

    $outerwear = Category::factory()->ownedBy(7)->create();
    $parkas = Category::factory()->ownedBy(7)->under($outerwear)->create();

    // The options already exclude the descendants, so this is refused before
    // the action runs; the domain refuses it again if anything reaches it
    // another way. Either way a cycle leaves a ring with no root and every
    // breadcrumb walk stops terminating, so what matters is that it did not
    // happen — and that the request did not 500 proving it.
    Livewire::test(EditCategory::class, ['record' => $outerwear->getRouteKey()])
        ->callAction('move', ['parent_category_id' => $parkas->id]);

    expect($outerwear->refresh()->parent_category_id)->toBeNull();
});

it('lists the categories the actor\'s team owns, and no others', function () {
    $this->actorForTeam(7);

    $mine = Category::factory()->ownedBy(7)->create();
    $theirs = Category::factory()->ownedBy(9)->create();
    $orphan = Category::factory()->create();

    Livewire::test(ListCategories::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs, $orphan]);
});

it('creates a collection through the domain action, carrying its descriptive fields', function () {
    $this->actorForTeam(7);

    Event::fake([CollectionCreated::class]);

    Livewire::test(CreateCollection::class)
        ->fillForm(['name' => 'Summer Sale', 'description' => 'Ends in August.'])
        ->call('create')
        ->assertHasNoFormErrors();

    $collection = ProductCollection::query()->firstOrFail();

    expect($collection->slug)->toBe('summer-sale')
        ->and($collection->team_id)->toBe(7)
        ->and($collection->description)->toBe('Ends in August.');

    Event::assertDispatched(CollectionCreated::class);
});

it('creates a brand through the domain action', function () {
    $this->actorForTeam(7);

    Event::fake([BrandCreated::class]);

    Livewire::test(CreateBrand::class)
        ->fillForm(['name' => 'Barbour', 'website' => 'https://example.test'])
        ->call('create')
        ->assertHasNoFormErrors();

    $brand = Brand::query()->firstOrFail();

    expect($brand->slug)->toBe('barbour')
        ->and($brand->team_id)->toBe(7)
        ->and($brand->website)->toBe('https://example.test');

    Event::assertDispatched(BrandCreated::class);
});

it('creates a vendor through the domain action', function () {
    $this->actorForTeam(7);

    Event::fake([VendorCreated::class]);

    Livewire::test(CreateVendor::class)
        ->fillForm(['name' => 'Northern Supply', 'contact_email' => 'buyer@example.test'])
        ->call('create')
        ->assertHasNoFormErrors();

    $vendor = Vendor::query()->firstOrFail();

    expect($vendor->slug)->toBe('northern-supply')
        ->and($vendor->team_id)->toBe(7)
        ->and($vendor->contact_email)->toBe('buyer@example.test');

    Event::assertDispatched(VendorCreated::class);
});

it('scopes every taxonomy resource to the actor\'s team', function () {
    $this->actorForTeam(7);

    Category::factory()->ownedBy(9)->create();
    ProductCollection::factory()->ownedBy(9)->create();
    Brand::factory()->ownedBy(9)->create();
    Vendor::factory()->ownedBy(9)->create();
    Category::factory()->ownedBy(7)->create();
    ProductCollection::factory()->ownedBy(7)->create();
    Brand::factory()->ownedBy(7)->create();
    Vendor::factory()->ownedBy(7)->create();

    expect(CategoryResource::getEloquentQuery()->count())->toBe(1)
        ->and(CollectionResource::getEloquentQuery()->count())->toBe(1)
        ->and(BrandResource::getEloquentQuery()->count())->toBe(1)
        ->and(VendorResource::getEloquentQuery()->count())->toBe(1);
});

it('shows no taxonomy at all to an actor working in no team', function () {
    $this->actingAs(TestUser::factory()->create());

    Category::factory()->ownedBy(7)->create();
    ProductCollection::factory()->create();
    Brand::factory()->create();
    Vendor::factory()->create();

    // Including the unowned rows. `where('team_id', null)` would compile to
    // `is null` and list exactly the orphans the policy denies every action on.
    expect(CategoryResource::getEloquentQuery()->count())->toBe(0)
        ->and(CollectionResource::getEloquentQuery()->count())->toBe(0)
        ->and(BrandResource::getEloquentQuery()->count())->toBe(0)
        ->and(VendorResource::getEloquentQuery()->count())->toBe(0);
});

/**
 * The three thin resources are thin, not absent. Each has a list and an edit
 * page nothing else in this suite mounts, and a resource whose table or form
 * throws on render is a resource that looks fine until somebody clicks it.
 */
it('lists and edits a collection', function () {
    $this->actorForTeam(7);

    $collection = ProductCollection::factory()->ownedBy(7)->create(['name' => 'Summer Sale']);
    $slug = $collection->slug;

    Livewire::test(ListCollections::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$collection]);

    Livewire::test(EditCollection::class, ['record' => $collection->getRouteKey()])
        ->assertFormFieldDoesNotExist('slug')
        ->fillForm(['name' => 'Autumn Sale', 'description' => 'Ends in November.'])
        ->call('save')
        ->assertHasNoFormErrors();

    // Saved through Filament's default, because the domain publishes no
    // `UpdateCollection` to delegate to — and the slug stays exactly where it
    // was, because it is addressable and renaming is not re-slugging.
    expect($collection->refresh()->name)->toBe('Autumn Sale')
        ->and($collection->description)->toBe('Ends in November.')
        ->and($collection->slug)->toBe($slug);
});

it('lists and edits a brand', function () {
    $this->actorForTeam(7);

    $brand = Brand::factory()->ownedBy(7)->create(['name' => 'Barbour']);

    Livewire::test(ListBrands::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$brand]);

    Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
        ->assertFormFieldDoesNotExist('slug')
        ->fillForm(['name' => 'Barbour International', 'website' => 'https://example.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($brand->refresh()->name)->toBe('Barbour International')
        ->and($brand->website)->toBe('https://example.test');
});

it('lists and edits a vendor', function () {
    $this->actorForTeam(7);

    $vendor = Vendor::factory()->ownedBy(7)->create(['name' => 'Northern Supply']);

    Livewire::test(ListVendors::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$vendor]);

    Livewire::test(EditVendor::class, ['record' => $vendor->getRouteKey()])
        ->assertFormFieldDoesNotExist('slug')
        ->fillForm(['name' => 'Northern Supply Co', 'contact_phone' => '01234 567890'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($vendor->refresh()->name)->toBe('Northern Supply Co')
        ->and($vendor->contact_phone)->toBe('01234 567890');
});

it('refuses another team\'s brand, collection and vendor by the same route key', function () {
    $this->actorForTeam(7);

    $theirs = Brand::factory()->ownedBy(9)->create();

    $refusal = null;

    try {
        Livewire::test(EditBrand::class, ['record' => $theirs->getRouteKey()]);
    } catch (ModelNotFoundException|AuthorizationException $exception) {
        $refusal = $exception;
    }

    expect($refusal)->not->toBeNull();
});
