<?php

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Liberu\Ecommerce\Catalog\Actions\PublishToChannel;
use Liberu\Ecommerce\Catalog\Enums\ProductStatus;
use Liberu\Ecommerce\Catalog\Enums\Visibility;
use Liberu\Ecommerce\Catalog\Filament\Support\Reachability;
use Liberu\Ecommerce\Catalog\Models\Product;

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The one test that keeps this package honest.
 *
 * `Reachability` decomposes a rule `Product::scopeAvailableOn()` also states, so
 * that the panel can name the fact standing in the way rather than only
 * answering yes or no. Two statements of one rule is exactly the drift the
 * domain warns about, and the only defence is asserting they agree across every
 * combination rather than the handful somebody thought of.
 */
it('agrees with the domain about every combination of the three facts', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $this->actorForTeam(7);

    $windows = [
        'unbounded' => [null, null],
        'already open' => [CarbonImmutable::parse('2026-01-01 00:00:00'), null],
        'not yet open' => [CarbonImmutable::parse('2026-12-01 00:00:00'), null],
        'already closed' => [null, CarbonImmutable::parse('2026-02-01 00:00:00')],
        'still open' => [null, CarbonImmutable::parse('2026-12-01 00:00:00')],
        'closed at this instant' => [null, CarbonImmutable::parse('2026-06-01 12:00:00')],
    ];

    foreach (ProductStatus::cases() as $status) {
        foreach (Visibility::cases() as $visibility) {
            foreach ($windows as $window => [$from, $until]) {
                $product = Product::factory()->ownedBy(7)->create([
                    'status' => $status,
                    'visibility' => $visibility,
                    'available_from' => $from,
                    'available_until' => $until,
                ]);

                $reachability = Reachability::of($product);
                $case = "{$status->value} / {$visibility->value} / {$window}";

                expect($reachability->reachable)->toBe($product->isAvailableOn(), $case)
                    ->and($reachability->listed)->toBe($product->isListedOn(), $case);
            }
        }
    }
});

it('names the lifecycle when that is what is in the way', function () {
    $product = Product::factory()->draft()->make();

    $reachability = Reachability::of($product);

    expect($reachability->reachable)->toBeFalse()
        ->and($reachability->label())->toBe('Not visible')
        ->and($reachability->summary())->toContain('its status is Draft rather than Active');
});

it('names the visibility when that is what is in the way', function () {
    $product = Product::factory()->hidden()->make();

    expect(Reachability::of($product)->summary())->toContain('its visibility is Hidden');
});

it('names a window that has not opened yet', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $product = Product::factory()->available('2026-12-01 00:00:00', null)->make();

    expect(Reachability::of($product)->summary())->toContain('it is not available until');
});

it('names a window that has already closed', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $product = Product::factory()->available(null, '2026-02-01 00:00:00')->make();

    expect(Reachability::of($product)->summary())->toContain('its availability ended on');
});

it('names every reason at once rather than only the first', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $product = Product::factory()->draft()->hidden()->available('2026-12-01 00:00:00', null)->make();

    $summary = Reachability::of($product)->summary();

    // Three switches are wrong, and an operator who fixes the one the screen
    // named is an operator who comes back to find it still invisible.
    expect($summary)->toContain('its status is Draft')
        ->and($summary)->toContain('its visibility is Hidden')
        ->and($summary)->toContain('not available until');
});

it('distinguishes a campaign link from a listing', function () {
    $unlisted = Product::factory()->unlisted()->make();
    $public = Product::factory()->make();

    expect(Reachability::of($unlisted)->label())->toBe('Link only')
        ->and(Reachability::of($unlisted)->reachable)->toBeTrue()
        ->and(Reachability::of($unlisted)->listed)->toBeFalse()
        ->and(Reachability::of($unlisted)->summary())->toContain('direct link only')
        ->and(Reachability::of($public)->label())->toBe('Listed');
});

it('says nothing about channels when it was not given them', function () {
    $product = Product::factory()->make();

    // The relation is not loaded, and this refuses to fetch it: a column that
    // did would cost a query per row. Silence is the honest answer.
    expect(Reachability::of($product)->liveChannels)->toBeNull()
        ->and(Reachability::of($product)->summary())->not->toContain('channel');
});

it('counts the channels actually carrying the product', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();

    app(PublishToChannel::class)->handle($product, 1);
    app(PublishToChannel::class)->handle($product, 2, CarbonImmutable::now()->addMonth());

    $product->load('publications');

    // Two publications, one of which starts next month. A count of rows would
    // say two and a merchant would go looking for a storefront carrying it.
    expect(Reachability::of($product)->liveChannels)->toBe(1)
        ->and(Reachability::of($product)->summary())->toContain('published on 1 channel');
});

it('says plainly when a product is on no channel at all', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    $product->load('publications');

    expect(Reachability::of($product)->summary())->toContain('published on no channel');
});

it('colours in agreement with the word it prints', function () {
    // The colour repeats the label rather than carrying it. Asserted because
    // the failure mode is a green badge reading "Not visible".
    expect(Reachability::of(Product::factory()->make())->color())->toBe('success')
        ->and(Reachability::of(Product::factory()->unlisted()->make())->color())->toBe('warning')
        ->and(Reachability::of(Product::factory()->draft()->make())->color())->toBe('danger');
});
