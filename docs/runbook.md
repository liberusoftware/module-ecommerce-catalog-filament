# Runbook

What goes wrong in production with this package, what it looks like, and what to do. Ordered by how
often it happens.

## The catalogue does not appear in the panel at all

**Looks like:** no Catalog navigation group, no products page, no 404 either — the routes simply do
not exist.

**Almost always:** the plugin is not attached. This package registers nothing into a panel on its
own; the application composes its panels and attaches `CatalogPlugin::make()` to one. Check the panel
provider.

**Otherwise:** `MODULES_ENABLED` does not name `ecommerce-catalog-filament`, so
`ModuleManagerServiceProvider` never registered the provider. Installation never implies boot here.

**Not the cause:** a stale component cache. This package registers a literal list rather than
discovering by directory scan, so `filament:cache-components` has nothing to go stale. Clearing it
will not bring the resources back.

```bash
php artisan about                      # does the module appear as enabled?
php artisan route:list --path=products # do the routes exist?
```

## Every list is empty, and there is definitely data

**Looks like:** the pages render, the tables say "No records", and the dashboard widget is not there
either.

**The actor has no `current_team_id`.** That is the deliberate answer, not a bug: the scope is an
explicit `whereRaw('1 = 0')`. Writing it as `where('team_id', null)` would compile to `is null` and
show precisely the unowned rows the policies deny every action on, which reads as a working panel
full of records nothing can be done to.

```php
// tinker
auth()->loginUsingId($userId);
Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam::id();   // null is the problem
```

Fix it on the application's side — the column belongs to the host, not to this package. Confirm with:

```php
Liberu\Ecommerce\Catalog\Filament\Resources\ProductResource::getEloquentQuery()->count();
```

## Some rows are invisible to everyone

**Looks like:** rows exist in `products` but no team's panel lists them.

**They have `team_id` null.** An orphan belongs to nobody, and both policies deny every action on
one — deliberately stricter than the read scope, because seeing an orphan is how it gets fixed and
editing one is how it gets stolen. This package does not list them either: a row whose every action
refuses reads as a broken panel rather than a denied one.

Assign them a team through the database or a console command, not through the panel. There is no
surface here that can, by design.

```sql
select count(*) from products where team_id is null;
```

## "Shoppers see: Not visible" and the merchant disagrees

**Read the description under the badge.** It names every fact standing in the way, not the first one
— an operator who fixes the one fact the screen named and comes back to find it still invisible is
the failure this column exists to prevent.

The three facts and where each is changed:

| Reads | Change it with |
| --- | --- |
| *its status is Draft rather than Active* | **Change status** — offers exactly the transitions the enum admits |
| *its visibility is Hidden* | **Change visibility** |
| *it is not available until …* / *its availability ended on …* | **Schedule availability** |

A fourth line, *published on no channel*, is a statement rather than a blocker. A host running one
storefront with no channels needs no publication; a host that scopes its storefront by channel does.

**"Link only" is not a fault.** It is the `unlisted` visibility working: reachable by a direct URL,
absent from listings, search and feeds. That is what a campaign link needs.

## The status action is not offered

Either the product is **archived** — terminal, and `changeStatus` refuses a terminal state, so an
action with an empty select is not offered at all — or the actor does not own it.

Archived is a one-way door in the domain and there is no surface here that reverses it. That is not
an omission; a product that has been offered has been seen and linked, and un-archiving one is a
decision that should be made with a console at hand.

## "Status not changed" / "Availability not changed" notifications

A domain exception, caught and shown rather than raised as a 500.

- **Status not changed** — the transition became illegal between the modal rendering and the submit,
  because the record moved in another tab or another process. Reload and try again.
- **Availability not changed** — the window closes before it opens. Rejected rather than normalised:
  swapping the ends silently would mean a mistyped year publishes something for a decade.
- **Variant not added** — the SKU belongs to another variant, anywhere in the estate. The unique
  index would have refused it too, as a constraint dump; this is the same refusal with the code named.
- **Category not moved** — the move would put a node under its own descendant. The select already
  excludes the descendants, so seeing this means two operators re-parented either end of one branch at
  once. The consequence it prevents is not a wrong answer but a breadcrumb walk that never terminates.

## Publishing shows a number instead of a channel name

Working as designed. Channels belong to `liberusoftware/ecommerce-commerce-core`, which this package
does not depend on. `ProductPublication::channel()` resolves a class from `catalog.channel_model` and
**throws** when the host has not set one, so a column using it would crash the panel on every
deployment that runs the catalogue without Commerce Core.

Setting `CATALOG_CHANNEL_MODEL` makes the relation loadable for the host's own code; it does not
change this table. Subclass `PublicationsRelationManager` if the deployment wants names.

## Variants and options are read-only

`manageVariants` delegates to `update`, which an **archived** product refuses. Editing an archived
product rewrites what orders and reports already point at.

If the product is not archived, the actor does not own it.

## There is no way to delete an option axis

Correct, and deliberate. The domain publishes `SetProductOption` and nothing that removes one.
Deleting the row directly would leave variants carrying values on an axis that no longer exists and
would dispatch no event, so nothing downstream would learn the product's shape had changed. Re-value
the axis instead. Tracked in
[presentation.md](presentation.md#known-deviation-an-option-axis-cannot-be-deleted).

## A product cannot be deleted

`ProductPolicy::delete()` allows only a **draft**. Anything that was ever offered is archived
instead — orders, reviews and reports still point at it. The delete action is hidden rather than
shown and refused.

## Events are not reaching a listener

Every write this package makes goes through a domain action, and the actions dispatch. If a listener
is not firing:

1. Confirm the write went through the panel and not through a console command or an import that
   writes Eloquent directly. `$product->update(['status' => …])` bypasses the transition table and
   dispatches nothing — that is the bypass this package exists not to reintroduce.
2. Confirm the listener is registered by the *application*. This package subscribes to nothing.
3. Turn on the domain's own telemetry to see the events as they happen:

```dotenv
CATALOG_TELEMETRY=true
CATALOG_TELEMETRY_CHANNEL=stack
```

Turn it off afterwards. A catalogue import writes thousands of records a minute.

## Upgrading the domain package broke a page

This package pins `liberusoftware/ecommerce-catalog: ^0.1`. A minor release inside that range that
changed an action signature, an enum case or a policy ability would surface here as a `TypeError`, an
`UnhandledMatchError` or a surface that renders but authorizes nothing.

Roll the domain package back to the last known-good `0.1.x` and open an issue against it. Do **not**
work around it in this package: a compatibility shim here would be a second copy of a domain rule,
which is the thing every deviation note in `presentation.md` exists to avoid.
