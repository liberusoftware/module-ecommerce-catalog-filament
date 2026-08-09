<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Event;
use Liberu\Ecommerce\Catalog\Actions\AddVariant;
use Liberu\Ecommerce\Catalog\Actions\SetProductOption;
use Liberu\Ecommerce\Catalog\Events\VariantAdded;
use Liberu\Ecommerce\Catalog\Events\VariantRemoved;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\Pages\EditProduct;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\Ecommerce\Catalog\Models\ProductVariant;
use Livewire\Livewire;

function variantsFor(Product $product)
{
    return Livewire::test(VariantsRelationManager::class, [
        'ownerRecord' => $product,
        'pageClass' => EditProduct::class,
    ]);
}

it('adds a variant through the domain action', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();

    Event::fake([VariantAdded::class]);

    variantsFor($product)
        ->callAction(TestAction::make('addVariant')->table(), [
            'sku' => 'JKT-001',
            'title' => 'Olive',
            'weight' => '1.20',
            'weight_unit' => 'kg',
            'requires_shipping' => true,
        ]);

    $variant = $product->variants()->firstOrFail();

    expect($variant->sku)->toBe('JKT-001')
        ->and($variant->title)->toBe('Olive')
        ->and($variant->position)->toBe(1);

    // `VariantAdded` is the event Pricing and Inventory Ledger care about most:
    // a new sellable id exists and neither of them has a row for it yet. A
    // `CreateAction` writing through the relation would tell neither.
    Event::assertDispatched(VariantAdded::class);
});

it('offers a picker per declared axis rather than three anonymous boxes', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(SetProductOption::class)->handle($product, 'Size', ['Small', 'Large']);

    variantsFor($product)
        ->mountAction(TestAction::make('addVariant')->table())
        // The axis the product actually declares, by name. Three boxes labelled
        // "Option 1" is the version of this that lets somebody type a size into
        // the colour axis.
        ->assertMountedActionModalSee('Size');
});

it('records the option values against the axes in order', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(SetProductOption::class)->handle($product, 'Size', ['Small', 'Large']);
    app(SetProductOption::class)->handle($product, 'Colour', ['Olive', 'Navy']);

    variantsFor($product)
        ->callAction(TestAction::make('addVariant')->table(), [
            'sku' => 'JKT-S-OLIVE',
            'option1' => 'Small',
            'option2' => 'Olive',
        ]);

    $variant = $product->variants()->firstOrFail();

    expect($variant->option1)->toBe('Small')
        ->and($variant->option2)->toBe('Olive')
        ->and($variant->option3)->toBeNull()
        ->and($variant->optionValues())->toBe(['Small', 'Olive']);
});

it('refuses a SKU another variant already holds, with a sentence rather than a stack trace', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(AddVariant::class)->handle($product, 'JKT-001');

    variantsFor($product)
        ->callAction(TestAction::make('addVariant')->table(), ['sku' => 'JKT-001'])
        ->assertNotified();

    // The unique index would have refused this too, as an integrity-constraint
    // dump. Reaching this assertion is what proves the panel caught it first.
    expect($product->variants()->count())->toBe(1);
});

it('removes a variant through the domain action, freeing its SKU', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    $variant = app(AddVariant::class)->handle($product, 'JKT-001');

    Event::fake([VariantRemoved::class]);

    variantsFor($product)
        ->callAction(TestAction::make('removeVariant')->table($variant));

    expect(ProductVariant::query()->where('sku', 'JKT-001')->exists())->toBeFalse();

    Event::assertDispatched(VariantRemoved::class);
});

it('keeps the SKU and the option values out of the edit form', function () {
    $this->actorForTeam(7);

    $product = Product::factory()->ownedBy(7)->create();
    app(SetProductOption::class)->handle($product, 'Size', ['Small']);
    $variant = app(AddVariant::class)->handle($product, 'JKT-001', 'Olive', ['Small']);

    variantsFor($product)
        ->mountAction(TestAction::make('edit')->table($variant))
        ->assertMountedActionModalSee('Barcode')
        // `AddVariant` is the only thing that claims a SKU, and the option
        // values are what the variant *is* rather than something about it.
        ->assertMountedActionModalDontSee('SKU')
        ->assertMountedActionModalDontSee('Size');
});

it('shows the variants of a product the actor may read, and no others', function () {
    $this->actorForTeam(7);

    expect(VariantsRelationManager::canViewForRecord(Product::factory()->ownedBy(9)->create(), EditProduct::class))->toBeFalse()
        ->and(VariantsRelationManager::canViewForRecord(Product::factory()->create(), EditProduct::class))->toBeFalse()
        ->and(VariantsRelationManager::canViewForRecord(Product::factory()->ownedBy(7)->create(), EditProduct::class))->toBeTrue();
});

it('offers no variant writes on an archived product', function () {
    $this->actorForTeam(7);

    $archived = Product::factory()->ownedBy(7)->archived()->create();
    $variant = app(AddVariant::class)->handle($archived, 'JKT-ARCH');

    // `manageVariants` delegates to `update`, which an archived product
    // refuses: editing one rewrites what orders and reports already point at.
    variantsFor($archived)
        ->assertActionHidden(TestAction::make('addVariant')->table())
        ->assertActionHidden(TestAction::make('removeVariant')->table($variant));
});
