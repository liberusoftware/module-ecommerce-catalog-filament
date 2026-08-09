# Adoption

Installing this package, enabling it, attaching it to a panel, and what the host has to supply.

## 1. Install

The domain package this presents is **not on Packagist**. Composer honours `repositories` only from
the root manifest, so the entry goes in the *application's* `composer.json`, not in this package's —
this package declares it for its own CI and that declaration does nothing for a consumer.

```bash
composer config repositories.ecommerce-catalog vcs https://github.com/liberusoftware/module-ecommerce-catalog
composer require liberusoftware/ecommerce-catalog-filament
```

That pulls `liberusoftware/ecommerce-catalog` with it. When the domain package reaches Packagist, the
`composer config repositories.*` line is the only thing to remove.

## 2. Enable the modules

Installing boots nothing: neither package ships `extra.laravel.providers`, so Composer discovery finds
no provider. `ModuleManagerServiceProvider` registers the provider each `module.json` names, and only
when the deployment asks for it:

```dotenv
MODULES_ENABLED=ecommerce-catalog,ecommerce-catalog-filament
```

Both, in that order. The presentation package registers nothing of its own — no migrations, no
policies, no config — so enabling it without the domain module gives you resources with no tables to
query and no gates to ask.

## 3. Migrate

The domain module's single migration is loaded by `CatalogServiceProvider`:

```bash
php artisan migrate
```

Every `Schema::create` in it is guarded by `hasTable`. On a host where `products`,
`product_categories`, `product_variants`, `product_options`, `collections`, `collection_items`, `tags`
and `product_tag` already exist, the migration creates only the three tables the module invents —
`ecommerce_catalog_brands`, `ecommerce_catalog_vendors` and `ecommerce_catalog_publications`.

## 4. Attach the plugin to a panel

The application owns its panels; this package never registers itself into one.

```php
use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Ecommerce\Catalog\Filament\CatalogPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->plugins([
                CatalogPlugin::make(),
            ]);
    }
}
```

`module.json` declares the plugin under `presentation.filament.admin`, which is the panel id this
package is tested against — but nothing enforces it. Attach it to whichever panel should carry the
catalogue, and to more than one if that is what the deployment needs.

The panel does **not** need to be tenant-aware. These resources scope on the actor's
`current_team_id` rather than on `Filament::getTenant()`, and `isScopedToTenant()` is `false`
throughout.

## 5. What the host has to supply

| Thing | Why | What happens without it |
| --- | --- | --- |
| A `current_team_id` on the authenticated user | It is the whole of `ProductPolicy` and `TaxonomyPolicy`, and the column every resource query scopes on | Every list is empty and the dashboard widget is hidden. Not an error — the deliberate answer for an actor working in no team |
| `CATALOG_TEAM_MODEL`, if the host's team model is not `App\Models\Team` | The domain resolves it from config at call time and never imports it | Only matters if something eager-loads the `team` relation. Nothing in this package does |
| `MODULES_ENABLED` naming both modules | Installation never implies boot | The resources exist as classes and appear nowhere |
| Colour aliases `success`, `warning`, `danger`, `gray` on the panel | Badge and notification colours | Filament's defaults apply |

Optional:

| Thing | Effect |
| --- | --- |
| `CATALOG_CHANNEL_MODEL` | Makes `ProductPublication::channel()` loadable. **This package still shows the channel as a number** — see the limitation note in [presentation.md](presentation.md#known-limitation-a-channel-is-a-number) — but a host's own surfaces can use the relation |
| `CATALOG_TELEMETRY=true` | The domain's own event logger starts recording. Off by default; a catalogue import writes thousands of records a minute |

## 6. What it does not bring

- **No price and no stock.** The domain owns neither, so no surface here shows or edits one. Pricing
  and Inventory Ledger extend a product through their own tables keyed on `products.id` and
  `product_variants.id`, and their own presentation packages present them.
- **No media library.** Image fields are paths or URLs. This package stores no files.
- **No storefront.** Everything here is the operator's side. `ProductQuery::storefront()` in the
  domain package is the shopper's.

## Upgrading

This is the first release; there is nothing to upgrade from.
