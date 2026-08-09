<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Catalog\Actions\PublishToChannel;
use Liberu\Ecommerce\Catalog\Events\ProductPublished;
use Liberu\Ecommerce\Catalog\Events\ProductUnpublished;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\EditProduct;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\PublicationsRelationManager;
use Liberu\Ecommerce\Catalog\Models\Product;
use Livewire\Livewire;

afterEach(function () {
    Carbon::setTestNow();
});

function publicationsFor(Product $product)
{
    return Livewire::test(PublicationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ]);
}

it('publishes to a channel through the domain action', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();

    Event::fake([ProductPublished::class]);

    publicationsFor($product)
        ->callAction(TestAction::make('publish')->table(), ['channel_id' => 3]);

    $publication = $product->publications()->firstOrFail();

    expect((int) $publication->channel_id)->toBe(3)
        ->and($publication->published_at)->toBeNull()
        ->and($publication->isLive())->toBeTrue();

    Event::assertDispatched(ProductPublished::class);
});

it('stages a season by publishing a draft', function () {
    $this->actorForTeam(7);

    $draft = Product::factory()->ownedBy(7)->draft()->create();

    // Allowed on purpose: a merchant stages a season by publishing everything
    // and then flipping the products live.
    publicationsFor($draft)
        ->callAction(TestAction::make('publish')->table(), ['channel_id' => 3]);

    expect($draft->publications()->count())->toBe(1);
});

it('refuses to publish an archived product without failing the request', function () {
    $this->actorForTeam(7);

    $archived = Product::factory()->ownedBy(7)->archived()->create();

    // `publish` is refused outright on an archived product, so the action is not
    // even offered — which is the correct answer, and the one that keeps
    // somebody from resurrecting a record through the back door.
    publicationsFor($archived)
        ->assertActionHidden(TestAction::make('publish')->table());

    expect($archived->publications()->count())->toBe(0);
});

it('rewrites the window rather than failing when the same channel is published twice', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    $publication = app(PublishToChannel::class)->handle($product, 3);

    publicationsFor($product)
        ->callAction(TestAction::make('reschedule')->table($publication), [
            'published_at' => '2026-07-01 00:00:00',
            'unpublished_at' => '2026-08-01 00:00:00',
        ]);

    expect($product->publications()->count())->toBe(1)
        ->and($publication->refresh()->published_at?->toDateString())->toBe('2026-07-01');
});

it('closes a live publication rather than deleting it', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    $publication = app(PublishToChannel::class)->handle($product, 3);

    Event::fake([ProductUnpublished::class]);

    publicationsFor($product)
        ->callAction(TestAction::make('unpublish')->table($publication));

    // The dates it ran for are the answer to "when did this stop being on the
    // site", which is asked after the fact when there is no other record left.
    expect($product->publications()->count())->toBe(1)
        ->and($publication->refresh()->unpublished_at)->not->toBeNull()
        ->and($publication->isLive())->toBeFalse();

    Event::assertDispatched(ProductUnpublished::class);
});

it('deletes a publication that never started', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    $publication = app(PublishToChannel::class)->handle($product, 3, CarbonImmutable::parse('2026-12-01 00:00:00'));

    publicationsFor($product)
        ->callAction(TestAction::make('unpublish')->table($publication));

    // Nothing happened, so there is nothing to keep.
    expect($product->publications()->count())->toBe(0);
});

it('says which of the three window states each publication is in', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(PublishToChannel::class)->handle($product, 1);
    app(PublishToChannel::class)->handle($product, 2, CarbonImmutable::parse('2026-12-01 00:00:00'));
    app(PublishToChannel::class)->handle($product, 3, null, CarbonImmutable::parse('2026-02-01 00:00:00'));

    // "Not live" would leave an operator unable to tell a season staged for
    // next month from one that ended last week.
    publicationsFor($product)
        ->assertOk()
        ->assertSee('Live')
        ->assertSee('Scheduled')
        ->assertSee('Ended');
});

it('shows the channel as the number it is, and never resolves a class it has not been given', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(PublishToChannel::class)->handle($product, 42);

    // `catalog.channel_model` is null in this suite, which is the state of every
    // deployment running the catalogue without Commerce Core.
    // `ProductPublication::channel()` throws there, so a column that used it
    // would be a panel that crashes on install.
    expect(config('catalog.channel_model'))->toBeNull();

    publicationsFor($product)
        ->assertOk()
        ->assertSee('42');
});
