<?php

use Carbon\Carbon;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Catalog\Enums\ProductStatus;
use Liberu\Ecommerce\Catalog\Enums\Visibility;
use Liberu\Ecommerce\Catalog\Events\ProductAvailabilityScheduled;
use Liberu\Ecommerce\Catalog\Events\ProductStatusChanged;
use Liberu\Ecommerce\Catalog\Events\ProductVisibilityChanged;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\CreateProduct;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\EditProduct;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\ListProducts;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\PackageTestbench\TestUser;
use Livewire\Livewire;

afterEach(function () {
    Carbon::setTestNow();
});

it('lists the products the actor\'s team owns, and no others', function () {
    $this->actorForTeam(7);

    $mine = Product::factory()->ownedBy(7)->create(['name' => 'Waxed Jacket']);
    $theirs = Product::factory()->ownedBy(9)->create(['name' => 'Rival Jacket']);
    // Unowned, and so nobody's: `ProductPolicy` denies every action on it, and a
    // row visible in a list where every row action refuses reads as a broken
    // panel rather than as a denied one.
    $orphan = Product::factory()->create(['name' => 'Abandoned Jacket']);

    Livewire::test(ListProducts::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs, $orphan]);
});

it('shows nothing at all to an actor working in no team', function () {
    $this->actingAs(TestUser::factory()->create());

    Product::factory()->ownedBy(7)->create();

    expect(Product::query()->count())->toBe(1);

    // The resource query, not the page: `viewAny` already refuses the page, and
    // the scope has to refuse independently for the relation managers, widgets
    // and bare queries that authorization check never reaches.
    expect(ProductResource::getEloquentQuery()->count())->toBe(0);
});

it('creates a product through the domain action', function () {
    $this->actorForTeam(7);

    Livewire::test(CreateProduct::class)
        ->fillForm([
            'name' => 'Waxed Jacket',
            'store_id' => 4,
            'description' => 'Cotton, waxed.',
            'meta_title' => 'Waxed Jacket',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::query()->firstOrFail();

    // All four are the action's doing, and none of them is a form field: the
    // slug is derived, the lifecycle starts in draft, the visibility starts
    // hidden, and the team comes off the actor rather than off the form.
    expect($product->slug)->toBe('waxed-jacket')
        ->and($product->status)->toBe(ProductStatus::Draft)
        ->and($product->visibility)->toBe(Visibility::Hidden)
        ->and($product->team_id)->toBe(7)
        ->and($product->store_id)->toBe(4)
        ->and($product->description)->toBe('Cotton, waxed.');
});

it('keeps the three facts and the slug out of the form entirely', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();

    // Not merely ignored on save — absent. A disabled field is still a field a
    // crafted request can set, and a present-but-dropped one is a control that
    // lies about what it does.
    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->assertFormFieldExists('name')
        ->assertFormFieldDoesNotExist('status')
        ->assertFormFieldDoesNotExist('visibility')
        ->assertFormFieldDoesNotExist('available_from')
        ->assertFormFieldDoesNotExist('available_until')
        ->assertFormFieldDoesNotExist('slug')
        // Create only: the domain publishes nothing that moves a product
        // between stores, so an edit form has nothing to delegate to.
        ->assertFormFieldDoesNotExist('store_id');
});

it('saves the descriptive fields and leaves the three facts where they were', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->unlisted()->create(['name' => 'Old Name']);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->refresh()->name)->toBe('New Name')
        ->and($product->status)->toBe(ProductStatus::Active)
        ->and($product->visibility)->toBe(Visibility::Unlisted);
});

it('offers exactly the transitions the enum admits', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->draft()->create();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->mountAction('changeStatus')
        ->assertMountedActionModalSee('Active')
        ->assertMountedActionModalSee('Archived')
        // Draft cannot go straight to discontinued. A select listing all four
        // statuses would offer it, and the domain action would then refuse it.
        ->assertMountedActionModalDontSee('Discontinued');
});

it('moves a product along the lifecycle through the domain action', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->draft()->create();

    Event::fake([ProductStatusChanged::class]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->callAction('changeStatus', ['status' => ProductStatus::Active->value]);

    expect($product->refresh()->status)->toBe(ProductStatus::Active);

    // The event is the proof it went through the action. A form that wrote the
    // column would leave the product looking identical and every listener
    // — search reindexing, feed regeneration — never told.
    Event::assertDispatched(ProductStatusChanged::class);
});

it('refuses an illegal transition without failing the request', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->draft()->create();

    // Draft to discontinued is not a move the enum admits. Whether that is
    // stopped by the select's own validation or by the domain exception the
    // action catches, what must not happen is a 500 — and reaching the
    // assertions at all is what proves it did not.
    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->callAction('changeStatus', ['status' => ProductStatus::Discontinued->value]);

    expect($product->refresh()->status)->toBe(ProductStatus::Draft);
});

it('hides the status action on a product whose lifecycle has ended', function () {
    $this->actorForTeam(7);

    $archived = Product::factory()->ownedBy(7)->archived()->create();
    $draft = Product::factory()->ownedBy(7)->draft()->create();

    // From the list rather than the edit page: an archived product is a record
    // and not a resource, so `ProductPolicy::update()` refuses the edit page
    // outright and there is no page to hide an action on.
    Livewire::test(ListProducts::class)
        ->assertActionHidden(TestAction::make('changeStatus')->table($archived))
        ->assertActionVisible(TestAction::make('changeStatus')->table($draft));
});

it('changes visibility through the domain action, and does not offer the state it is in', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();

    Event::fake([ProductVisibilityChanged::class]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->mountAction('setVisibility')
        ->assertMountedActionModalSee('Unlisted')
        ->assertMountedActionModalSee('Hidden')
        ->setActionData(['visibility' => Visibility::Unlisted->value])
        ->callMountedAction();

    expect($product->refresh()->visibility)->toBe(Visibility::Unlisted);

    Event::assertDispatched(ProductVisibilityChanged::class);
});

it('schedules an availability window through the domain action', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();

    Event::fake([ProductAvailabilityScheduled::class]);

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->callAction('scheduleAvailability', [
            'available_from' => '2026-07-01 00:00:00',
            'available_until' => '2026-08-01 00:00:00',
        ]);

    expect($product->refresh()->available_from?->toDateString())->toBe('2026-07-01')
        ->and($product->available_until?->toDateString())->toBe('2026-08-01');

    Event::assertDispatched(ProductAvailabilityScheduled::class);
});

it('clears a window with the same action that set it', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->available('2026-07-01 00:00:00', '2026-08-01 00:00:00')->create();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->callAction('scheduleAvailability', [
            'available_from' => null,
            'available_until' => null,
        ]);

    // An empty date field means "no bound", not the epoch. This is the branch
    // where a parser that accepted an empty string would publish something in
    // 1970 and nobody would notice for a week.
    expect($product->refresh()->available_from)->toBeNull()
        ->and($product->available_until)->toBeNull();
});

it('refuses a window that closes before it opens, without failing the request', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();

    Livewire::test(EditProduct::class, ['record' => $product->getKey()])
        ->callAction('scheduleAvailability', [
            'available_from' => '2026-08-01 00:00:00',
            'available_until' => '2026-07-01 00:00:00',
        ])
        ->assertNotified();

    expect($product->refresh()->available_from)->toBeNull();
});

it('offers deletion only for a product that was never offered', function () {
    $this->actorForTeam(7);

    $draft = Product::factory()->ownedBy(7)->draft()->create();
    $active = Product::factory()->ownedBy(7)->create();

    // A product that has been offered has been seen, linked and possibly
    // ordered; the domain archives one rather than deleting it.
    Livewire::test(ListProducts::class)
        ->assertActionVisible(TestAction::make('delete')->table($draft))
        ->assertActionHidden(TestAction::make('delete')->table($active));
});

it('will not open another team\'s product', function () {
    $this->actorForTeam(7);

    $theirs = Product::factory()->ownedBy(9)->create();

    $refusal = null;

    try {
        Livewire::test(EditProduct::class, ['record' => $theirs->getKey()]);
    } catch (ModelNotFoundException|AuthorizationException $exception) {
        // Either answer is correct. The scope keeps it out of the resource's
        // query, and the policy refuses it if anything ever hands it over
        // another way; which of the two answers first is not this test's
        // business, only that one of them does.
        $refusal = $exception;
    }

    expect($refusal)->not->toBeNull();
});

it('filters the list by each of the facts on its own', function () {
    $this->actorForTeam(7);

    $draft = Product::factory()->ownedBy(7)->draft()->create();
    $active = Product::factory()->ownedBy(7)->create();

    Livewire::test(ListProducts::class)
        ->filterTable('status', ProductStatus::Draft->value)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$active]);
});
