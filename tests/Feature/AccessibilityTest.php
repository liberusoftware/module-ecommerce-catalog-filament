<?php

use Carbon\Carbon;
use Filament\Actions\Action;
use Liberu\Ecommerce\Catalog\Actions\AddVariant;
use Liberu\Ecommerce\Catalog\Actions\PublishToChannel;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource\Pages\ListCategories;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\EditProduct;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\ListProducts;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\PublicationsRelationManager;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use Liberu\Ecommerce\Catalog\Models\Category;
use Liberu\Ecommerce\Catalog\Models\Product;
use Livewire\Livewire;

// What a keyboard and a screen reader can get out of these surfaces. The two
// things worth a test are the two that regress silently: a state rendered as
// colour or as an icon with no words, and an action whose only affordance is an
// icon with no accessible name. Both look correct in a screenshot.

afterEach(function () {
    Carbon::setTestNow();
});

it('renders a product\'s status and visibility as text and not only as a badge colour', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->draft()->unlisted()->create(['name' => 'Waxed Jacket']);

    // Asserted on the column's formatted state rather than on the page text:
    // the status filter puts every status name into the HTML regardless of what
    // is in the table, so `assertSee('Draft')` would pass on an empty list.
    Livewire::test(ListProducts::class)
        ->assertOk()
        ->assertTableColumnFormattedStateSet('status', 'Draft', $product)
        ->assertTableColumnFormattedStateSet('visibility', 'Unlisted', $product)
        ->assertTableColumnFormattedStateSet('reachability', 'Not visible', $product);
});

it('says in words whether a shopper can see a product, and why not', function () {
    $this->actorForTeam(7);

    Product::factory()->ownedBy(7)->hidden()->create();

    // A red dot is the same to a screen reader as a green one, and the same to
    // anybody who cannot separate the two colours. The verdict is a word and
    // the reason is a sentence.
    Livewire::test(ListProducts::class)
        ->assertOk()
        ->assertSee('Not visible')
        ->assertSee('its visibility is Hidden');
});

it('says in words whether a variant ships', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(AddVariant::class)->handle($product, 'JKT-001', 'Olive', [], ['requires_shipping' => false]);
    app(AddVariant::class)->handle($product, 'JKT-002', 'Navy');

    Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->assertOk()
        ->assertSee('Ships')
        ->assertSee('No shipping');
});

it('says in words which of the three window states a publication is in', function () {
    Carbon::setTestNow('2026-06-01 12:00:00');

    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(PublishToChannel::class)->handle($product, 1);

    Livewire::test(PublicationsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ])
        ->assertOk()
        ->assertSee('Live');
});

it('gives every icon-bearing action an accessible name', function () {
    /** @var array<string, Action> $actions */
    $actions = [
        'product status' => ProductResource::changeStatusAction(),
        'product visibility' => ProductResource::setVisibilityAction(),
        'product availability' => ProductResource::scheduleAvailabilityAction(),
        'category move' => CategoryResource::moveAction(),
    ];

    foreach ($actions as $action) {
        // An action carrying an icon is an action Filament may render as an
        // icon button, and an icon button with no label is a control a screen
        // reader announces as "button".
        expect($action->getIcon())->not->toBeNull()
            ->and((string) $action->getLabel())->not->toBe('');
    }
});

it('heads computed columns with words rather than with their attribute names', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(AddVariant::class)->handle($product, 'JKT-001');

    Category::factory()->ownedBy(7)->create();

    // Filament humanises a column name into a heading when none is given, which
    // is how `variants_count` becomes the heading "Variants count" and
    // `products_count` becomes "Products count". Neither is what the column
    // holds, and the label is the only thing keeping them out.
    Livewire::test(ListProducts::class)
        ->assertOk()
        ->assertSee('Variants')
        ->assertDontSee('Variants count')
        ->assertSee('Shoppers see');

    Livewire::test(ListCategories::class)
        ->assertOk()
        ->assertSee('Products')
        ->assertDontSee('Products count');
});

it('states the whole answer on the page that edits it', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->draft()->create();

    // Three header actions and three columns still leave an operator working
    // out what they add up to. The subheading says it.
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertOk()
        ->assertSee('No shopper can see this product')
        ->assertSee('published on no channel');
});
