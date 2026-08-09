# Ecommerce: Catalog Filament

> This optional Filament 5 presentation package presents exactly one independent domain module. It contributes reusable resources, pages, widgets, schemas, tables, infolists, and actions to application-owned panels while delegating authorization, validation, tenancy, persistence, and business rules to the [ecommerce-catalog](https://github.com/liberusoftware/module-ecommerce-catalog) public boundary.

[Software](https://liberusoftware.com) ·
[Hosting](https://liberuhosting.com) ·
[Services](https://liberuservices.com) ·
[Liberu Group](https://liberugroup.com)

![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white) ![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white) ![Filament](https://img.shields.io/badge/Filament-5-FDAE4B)
[![Latest release](https://img.shields.io/github/v/release/liberusoftware/module-ecommerce-catalog-filament?sort=semver)](https://github.com/liberusoftware/module-ecommerce-catalog-filament/releases/latest) [![Tests](https://github.com/liberusoftware/module-ecommerce-catalog-filament/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/liberusoftware/module-ecommerce-catalog-filament/actions/workflows/tests.yml)

## Features

- Fully compatible with **Laravel 13**, **PHP 8.5**, and **Pest 5**.
- Built following the domain-driven design guidelines of the Liberu architecture.
- Reusable, presenting a clean public contract and boundaries.
- Adheres to the strict database, security, and authorization standards of Liberu.

## Can a shopper see this?

Status, visibility and the effective dates are three independent facts, and the domain is right to
keep them apart. An operator looking at three switches still has to work out what they add up to.

Every product list in this package leads with the answer, and when the answer is no it names the
reason:

| Shoppers see | Means |
| --- | --- |
| **Listed** | In listings, search and feeds |
| **Link only** | Reachable by a direct URL, deliberately absent from listings — the unlisted case |
| **Not visible** | No shopper can reach it, and the badge's description says which of the three facts is why |

The edit page says it in a sentence under the title: *"No shopper can see this product, because its
status is Draft rather than Active and its visibility is Hidden. It is published on no channel, so
any storefront that asks for one will not carry it."*

`Support\Reachability` computes that from attributes already loaded, so a page of fifty products
costs no extra queries. It restates a rule `Product::scopeAvailableOn()` also states, which is a real
risk, and `tests/Feature/ReachabilityTest.php` asserts the two agree across the whole
status × visibility × window matrix rather than the handful somebody thought of.

## What it contributes

| Surface | Covers |
| --- | --- |
| `Resources\ProductResource` (list, create, edit) | Products, their reachability, and the lifecycle, visibility and availability window as three separate actions |
| ↳ `VariantsRelationManager` | Variants, added and removed through the domain's actions, with a picker per declared axis |
| ↳ `OptionsRelationManager` | The axes a product varies along, through `SetProductOption` |
| ↳ `TagsRelationManager` | Tags, set by name through `SyncProductTags` |
| ↳ `CollectionsRelationManager` | Collection membership, through the two collection actions |
| ↳ `PublicationsRelationManager` | Channel publication and each publication's own window |
| `Resources\CategoryResource` (list, create, edit) | The tree, with re-parenting through `MoveCategory` and its cycle guard |
| `Resources\CollectionResource` (list, create, edit) | Merchandised groupings |
| ↳ `ProductsRelationManager` | What is in a collection, in the order a storefront renders it |
| `Resources\BrandResource` (list, create, edit) | Brands |
| `Resources\VendorResource` (list, create, edit) | Vendors |
| `Widgets\CatalogOverview` | How much of the catalogue a shopper can actually see |

Every write that the domain publishes an action for goes through it, so the domain events come with
them — `ProductCreated`, `ProductStatusChanged`, `ProductVisibilityChanged`,
`ProductAvailabilityScheduled`, `ProductPublished`, `ProductUnpublished`, `VariantAdded`,
`VariantRemoved`, `ProductOptionSet`, `ProductTagsChanged`, `ProductAddedToCollection`,
`ProductRemovedFromCollection`, `CategoryCreated`, `CategoryMoved`, `CollectionCreated`,
`BrandCreated` and `VendorCreated`. Where the domain deliberately publishes none, the field is kept
out of the form rather than left to Filament's default; both cases are listed in
[docs/presentation.md](docs/presentation.md).

Authorization is `ProductPolicy` and `TaxonomyPolicy` throughout, and every resource query is scoped
to the team the actor is working in — the same column the policies read.

## Requirements

- **PHP 8.5**
- **Composer 2**
- A supported database (e.g. MySQL, PostgreSQL, SQLite)

## Quick start

The domain package this presents is not on Packagist, so add its repository alongside the
requirement:

```bash
composer config repositories.ecommerce-catalog vcs https://github.com/liberusoftware/module-ecommerce-catalog
composer require liberusoftware/ecommerce-catalog-filament
```

Installing boots nothing — the package ships no `extra.laravel.providers`. Enable the module and the
domain module it presents:

```dotenv
MODULES_ENABLED=ecommerce-catalog,ecommerce-catalog-filament
```

Then attach the plugin to whichever panel should carry these resources. The application owns its
panels; this package never registers itself into one:

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

The plugin's id is `liberu-ecommerce-catalog`, and `module.json` declares it under
`presentation.filament.admin`.

The resources scope themselves to the team the panel user is working in — the `current_team_id` the
policies read — rather than to Filament's tenant, so the plugin works on a panel with no tenancy
configured at all.

## Documentation

- [Adoption and upgrade](docs/adoption.md) — installing, enabling the module, attaching the plugin
  to a panel, and what a host has to supply
- [Presentation](docs/presentation.md) — the surface inventory, navigation, authorization, the
  domain action behind every write, the deliberate deviations, theme integration, accessibility, and
  cache/discovery behaviour
- [Runbook](docs/runbook.md) — production failure modes and the operator's response to each
- [Changelog](CHANGELOG.md) — release notes
- [Liberu Main Documentation](https://github.com/liberusoftware/documentation)
- [Architecture & Standards Index](https://github.com/liberusoftware/documentation/tree/main/architecture)

## Related Liberu Projects

| Project | Repository | Purpose |
| --- | --- | --- |
| **Boilerplate** | [liberusoftware/boilerplate-laravel](https://github.com/liberusoftware/boilerplate-laravel) | Shared Laravel application foundation and reference composition |
| **CMS** | [liberu-cms/cms-laravel](https://github.com/liberu-cms/cms-laravel) | Structured content, publishing, media, multisite, and headless delivery |
| **CRM** | [liberu-crm/crm-laravel](https://github.com/liberu-crm/crm-laravel) | Customer data, sales, marketing, service, and customer success |
| **Billing** | [liberu-billing/billing-laravel](https://github.com/liberu-billing/billing-laravel) | Products, subscriptions, invoicing, payments, and provisioning |
| **Accounting** | [liberu-accounting/accounting-laravel](https://github.com/liberu-accounting/accounting-laravel) | Ledgers, banking, tax, expenses, close, and financial reporting |
| **Ecommerce** | [liberu-ecommerce/ecommerce-laravel](https://github.com/liberu-ecommerce/ecommerce-laravel) | Catalog, checkout, orders, fulfillment, returns, B2B, and omnichannel commerce |
| **Control Panel** | [liberu-control-panel/control-panel-laravel](https://github.com/liberu-control-panel/control-panel-laravel) | Hosting, infrastructure, DNS, mail, databases, backups, and security operations |
| **Automation** | [liberu-automation/automation-laravel](https://github.com/liberu-automation/automation-laravel) | Governed workflows, provider-neutral AI, approvals, and connectors |

## Security

Please do not report security vulnerabilities through public GitHub issues.
Follow our [Security Policy](https://github.com/liberusoftware/documentation/blob/main/architecture/SECURITY.md) for private reporting and supported versions.

## License

This project is open-source software. You may use, modify, and distribute it
under the terms described in [LICENSE.md](LICENSE.md).

The linked license text is authoritative; this summary is not legal advice.

## Feedback and contributing

Feedback and contributions are welcome. You can help by reporting reproducible
bugs, proposing focused enhancements, improving documentation or translations,
and submitting tested code changes.

Before contributing, please read [CONTRIBUTING.md](https://github.com/liberusoftware/documentation/blob/main/standards/CONTRIBUTING.md) and our
[Code of Conduct](https://github.com/liberusoftware/documentation/blob/main/architecture/CODE_OF_CONDUCT.md). Search existing issues first, then use
the appropriate issue template. Pull requests should explain the problem and
approach, remain focused, include or update tests, pass the required workflows,
and document user-visible or breaking changes.

## Contributors

Thank you to everyone who helps improve Liberu.

<a href="https://github.com/liberusoftware/module-ecommerce-catalog-filament/graphs/contributors">
  <img src="https://contrib.rocks/image?repo=liberusoftware/module-ecommerce-catalog-filament" alt="Contributors to liberusoftware/module-ecommerce-catalog-filament">
</a>

[View the full contributors graph](https://github.com/liberusoftware/module-ecommerce-catalog-filament/graphs/contributors).
