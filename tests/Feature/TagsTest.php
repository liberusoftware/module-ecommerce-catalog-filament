<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Catalog\Actions\SyncProductTags;
use Liberu\Ecommerce\Catalog\Events\ProductTagsChanged;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\EditProduct;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\TagsRelationManager;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\Ecommerce\Catalog\Models\Tag;
use Livewire\Livewire;

function tagsFor(Product $product)
{
    return Livewire::test(TagsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ]);
}

it('sets tags by name through the domain action, creating what is missing', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();

    Event::fake([ProductTagsChanged::class]);

    tagsFor($product)
        ->callAction(TestAction::make('setTags')->table(), [
            'tags' => ['Waterproof', 'Wool'],
        ]);

    expect($product->tags()->pluck('name')->all())->toEqualCanonicalizing(['Waterproof', 'Wool']);

    Event::assertDispatched(ProductTagsChanged::class);
});

it('folds two spellings of one word into one tag', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(SyncProductTags::class)->handle($product, ['Water Resistant']);

    tagsFor($product)
        ->callAction(TestAction::make('setTags')->table(), [
            'tags' => ['water resistant'],
        ]);

    // The domain matches on the slug, so the vocabulary does not grow a
    // near-duplicate every time somebody types. Attaching a tag by id through a
    // pivot would have created a second row here.
    expect(Tag::query()->count())->toBe(1)
        ->and($product->tags()->count())->toBe(1);
});

/**
 * This is the whole-set assertion, and it is made through the remove action
 * rather than by submitting a shrunken list into the prefilled field.
 *
 * Both go through `SyncProductTags` with the full set, so both prove the same
 * thing about this package. The form-submission variant additionally depends on
 * the Filament test helper *replacing* an array already in the schema rather
 * than merging into it, and that changed within the `^5.0` range this package
 * declares — it merges on 5.6.5, which `--prefer-lowest` resolves to. Raising
 * the Filament constraint to make a test helper behave is a consumer-facing
 * change bought for nothing, so the assertion moved to the path that does not
 * straddle the difference.
 */
it('treats the field as the whole set rather than as an addition', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(SyncProductTags::class)->handle($product, ['Wool', 'Waterproof']);

    $wool = $product->tags()->where('name', 'Wool')->firstOrFail();

    tagsFor($product)
        ->callAction(TestAction::make('removeTag')->table($wool));

    // Detached because the action was handed the set without it, not because
    // anything detached a pivot row. The tag itself survives: it is a shared
    // word with no owner, and another product may be using it.
    expect($product->tags()->pluck('name')->all())->toBe(['Waterproof'])
        ->and(Tag::query()->count())->toBe(2);
});

it('offers the tags already on the product when the form opens', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(SyncProductTags::class)->handle($product, ['Wool']);

    // A field that opened empty would read as "no tags", and saving it would
    // silently detach every one of them.
    tagsFor($product)
        ->mountAction(TestAction::make('setTags')->table())
        ->assertActionDataSet(['tags' => ['Wool']]);
});

it('offers no tag writes on an archived product', function () {
    $this->actorForTeam(7);

    $archived = Product::factory()->ownedBy(7)->archived()->create();

    tagsFor($archived)
        ->assertActionHidden(TestAction::make('setTags')->table());
});
