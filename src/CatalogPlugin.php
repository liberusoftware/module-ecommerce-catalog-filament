<?php

namespace Liberu\Ecommerce\Catalog\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Ecommerce\Catalog\Filament\Resources\BrandResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\CategoryResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\CollectionResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource;
use Liberu\Ecommerce\Catalog\Filament\Resources\VendorResource;
use Liberu\Ecommerce\Catalog\Filament\Widgets\CatalogOverview;

/**
 * What this package contributes to a panel the application composes.
 *
 * Listed rather than discovered by directory scan: discovery reads the
 * filesystem on every boot to rediscover a set that is fixed at release, and a
 * scan rooted at `src/` would also sweep up anything a later version happens to
 * put there. The list is the same five resources either way, and it is the one
 * place to read what attaching this plugin adds.
 *
 * There is no `TagResource`, and that is deliberate — see
 * `docs/presentation.md`. A tag has no owner and no policy, so a resource for
 * one would be a surface with nothing to authorize against.
 */
final class CatalogPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'liberu-ecommerce-catalog';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->resources([
                ProductResource::class,
                CategoryResource::class,
                CollectionResource::class,
                BrandResource::class,
                VendorResource::class,
            ])
            ->widgets([
                CatalogOverview::class,
            ]);
    }

    public function boot(Panel $panel): void {}
}
