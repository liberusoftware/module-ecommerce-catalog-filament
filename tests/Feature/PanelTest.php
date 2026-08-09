<?php

use Filament\Facades\Filament;
use Liberu\Ecommerce\Catalog\Filament\CatalogPlugin;
use Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource;
use Liberu\Ecommerce\Catalog\Filament\Widgets\CatalogOverview;
use Liberu\Ecommerce\Catalog\Models\Category;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\PackageTestbench\TestUser;
use Livewire\Livewire;

it('contributes its resources and widget to the panel that attaches it', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->getPlugin(CatalogPlugin::make()->getId()))->toBeInstanceOf(CatalogPlugin::class)
        ->and($panel->getResources())->toContain(
            ProductResource::class,
            CategoryResource::class,
            CollectionResource::class,
            BrandResource::class,
            VendorResource::class,
        )
        ->and($panel->getWidgets())->toContain(CatalogOverview::class);
});

it('declares in its manifest exactly the plugin it ships', function () {
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/module.json'), true, flags: JSON_THROW_ON_ERROR);

    $plugins = $manifest['presentation']['filament']['admin'];

    expect($plugins)->toBe([CatalogPlugin::class]);

    foreach ($plugins as $plugin) {
        expect(class_exists($plugin))->toBeTrue();
    }
});

/**
 * The plugin lists what it contributes rather than scanning `src/` for it, which
 * is what makes `filament:cache-components` a no-op worth running and a boot
 * with no filesystem walk. The cost of a list is that a class added later is
 * simply absent from the panel — no error, no missing-file warning, just a
 * resource nobody can reach. This is the test that turns that silence into a
 * failure.
 */
it('registers exactly the resources and widgets the package ships', function () {
    $panel = Filament::getPanel('admin');
    $src = dirname(__DIR__, 2).'/src';

    $shipped = function (string $directory, string $namespace) use ($src): array {
        $classes = array_map(
            fn (string $path): string => $namespace.'\\'.basename($path, '.php'),
            (array) glob($src.'/'.$directory.'/*.php'),
        );

        sort($classes);

        return $classes;
    };

    $registered = function (array $classes): array {
        $ours = array_values(array_filter(
            $classes,
            fn (string $class): bool => str_starts_with($class, 'Liberu\\Ecommerce\\Catalog\\Filament\\'),
        ));

        sort($ours);

        return $ours;
    };

    expect($registered($panel->getResources()))
        ->toBe($shipped('Resources', 'Liberu\\Ecommerce\\Catalog\\Filament\\Resources'))
        ->and($registered($panel->getWidgets()))
        ->toBe($shipped('Widgets', 'Liberu\\Ecommerce\\Catalog\\Filament\\Widgets'));
});

it('counts what a shopper can see rather than what merely exists', function () {
    $this->actorForTeam(7);

    Product::factory()->ownedBy(7)->create();
    Product::factory()->ownedBy(7)->unlisted()->create();
    Product::factory()->ownedBy(7)->draft()->create();
    Product::factory()->ownedBy(7)->hidden()->create();
    // Another team's, and live. Counting it would put somebody else's
    // catalogue on this merchant's dashboard.
    Product::factory()->ownedBy(9)->create();

    Category::factory()->ownedBy(7)->create();

    Livewire::test(CatalogOverview::class)
        ->assertOk()
        // Four products in this team; one listed; two of the four reachable by
        // nobody at all.
        ->assertSee('1 listed to shoppers')
        ->assertSee('no shopper can see these at all');
});

it('shows the widget to nobody working outside a team', function () {
    $this->actingAs(TestUser::factory()->create());

    expect(CatalogOverview::canView())->toBeFalse();
});
