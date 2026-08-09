# Presentation

What attaching `CatalogPlugin` to a panel puts in front of an operator, what each surface asks the
gate before it renders, and which domain action each write goes through.

## Inventory

Everything here is registered by `CatalogPlugin::register()`. There is no directory scan; see
[Cache and discovery](#cache-and-discovery).

| Surface | Class |
| --- | --- |
| Resource | `Resources\ProductResource` |
| ↳ page | `…\ProductResource\Pages\ListProducts` (`/products`) |
| ↳ page | `…\ProductResource\Pages\CreateProduct` (`/products/create`) |
| ↳ page | `…\ProductResource\Pages\EditProduct` (`/products/{record}/edit`) |
| ↳ relation manager | `…\ProductResource\RelationManagers\VariantsRelationManager` |
| ↳ relation manager | `…\ProductResource\RelationManagers\OptionsRelationManager` |
| ↳ relation manager | `…\ProductResource\RelationManagers\TagsRelationManager` |
| ↳ relation manager | `…\ProductResource\RelationManagers\CollectionsRelationManager` |
| ↳ relation manager | `…\ProductResource\RelationManagers\PublicationsRelationManager` |
| Resource | `Resources\CategoryResource` (list, create, edit) |
| Resource | `Resources\CollectionResource` (list, create, edit) |
| ↳ relation manager | `…\CollectionResource\RelationManagers\ProductsRelationManager` |
| Resource | `Resources\BrandResource` (list, create, edit) |
| Resource | `Resources\VendorResource` (list, create, edit) |
| Widget | `Widgets\CatalogOverview` |
| Support | `Support\PanelTeam`, `Support\Reachability`, `Support\Instant` |

The package ships no standalone pages, no custom Blade views, no CSS and no JavaScript.

### What is deliberately not here

- **No `TagResource`.** `Tag` has no policy in the domain and no `team_id`, because a tag is a shared
  word with no owner. A resource for one would be a surface with nothing to authorize against, and
  Filament treats a model with no policy as one nobody has refused. Tags are reached from the product
  they are on, where `ProductPolicy::update()` is the ability that can answer.
- **No resource for variants, options or publications.** Each belongs to exactly one product and is
  meaningless without it; each is a relation manager on the product instead, gated on the product.
- **No trashed filter and no restore action.** `Product` and `ProductCollection` soft-delete, and the
  domain publishes nothing that restores one. A restore through Eloquent would bring back a row and
  dispatch nothing.
- **No bulk actions.** Every write here goes through a domain action one record at a time, and a bulk
  action that looped over them would be a progress bar over N events with no transaction and no way
  to report which record in the middle failed. A host that needs one has the actions.
- **No `canCreate(): false` anywhere.** The reference Filament package forces it because
  `ChannelPolicy` publishes no `create`; here both `ProductPolicy` and `TaxonomyPolicy` publish one,
  so all five resources have a real answer to give. The unanswered cases in this domain are the four
  models with **no policy at all** — `ProductVariant`, `ProductOption`, `ProductPublication` and
  `Tag` — and every action over those carries an explicit `visible()` gate on the owning product
  rather than letting a default decide. That is the same rule applied where it actually bites.

## Navigation

Every resource lands in the navigation group **Catalog**:

| Entry | Group | Icon | Lands on |
| --- | --- | --- | --- |
| Products | Catalog | `heroicon-o-cube` | `ListProducts` |
| Categories | Catalog | `heroicon-o-rectangle-group` | `ListCategories` |
| Collections | Catalog | `heroicon-o-rectangle-stack` | `ListCollections` |
| Brands | Catalog | `heroicon-o-sparkles` | `ListBrands` |
| Vendors | Catalog | `heroicon-o-truck` | `ListVendors` |

`CatalogOverview` is a dashboard widget and has no navigation entry of its own. It is registered with
the panel, so it appears on whatever dashboard the panel composes, subject to `canView()`.

No resource sets a navigation sort, so the host panel's own ordering applies.

## Reachability

The one thing this UI does that a generated CRUD would not.

`Support\Reachability::of($product)` answers, from attributes already loaded:

| Field | Meaning |
| --- | --- |
| `reachable` | A direct URL resolves to it |
| `listed` | It is also in listings, search and feeds |
| `blockers` | Why it is not reachable — one sentence fragment per fact standing in the way |
| `liveChannels` | How many publications are in force, or **null** when the relation was not loaded |

Rendered as a badge column headed **Shoppers see** — `Listed` / `Link only` / `Not visible` — whose
description is the whole sentence, and as the subheading of `EditProduct`.

Three notes on why it is shaped this way:

- **It restates a rule the domain also states.** `Product::scopeAvailableOn()` answers yes or no; a
  panel that could only say "no" is the one that generates the support ticket, so this decomposes the
  same rule to name the reason. The defence against drift is not care, it is
  `tests/Feature/ReachabilityTest.php`, which asserts `reachable` equals `Product::isAvailableOn()`
  and `listed` equals `Product::isListedOn()` across every combination of status, visibility and six
  window shapes — including a window closing at exactly this instant, where an off-by-one would be a
  product the panel calls live and the storefront 404s.
- **It is pure PHP.** `isAvailableOn()` costs a query per call and is documented as such; a column
  using it would cost one per row. `Reachability` reads the model's own attributes, and
  `ProductResource::getEloquentQuery()` eager loads `publications` so the channel count costs nothing
  either.
- **It never lazily loads.** With the `publications` relation absent, `liveChannels` is null and the
  sentence simply says nothing about channels. Silence is the honest answer; a fetch would be a
  query per row hidden inside a column.

**Catalogue-wide, not per channel.** A publication is a fourth fact with its own window, per channel,
so it cannot collapse into one verdict. The count is reported alongside — *"published on no channel,
so any storefront that asks for one will not carry it"* — and the per-channel detail lives in the
publications relation manager, which labels each row `Live`, `Scheduled` or `Ended`.

## Authorization

Every surface asks the gate; nothing here is visible because it was registered.

| Surface | Ability | Asked about |
| --- | --- | --- |
| `ProductResource` list/view/edit/delete | Filament's resource defaults → `ProductPolicy` | the product |
| `ProductResource::changeStatusAction()` | `changeStatus` | the product |
| `ProductResource::setVisibilityAction()` | `update` | the product |
| `ProductResource::scheduleAvailabilityAction()` | `update` | the product |
| `VariantsRelationManager` (visible) | `view` | the product |
| `VariantsRelationManager` add/edit/remove | `manageVariants` | the product |
| `OptionsRelationManager` (visible) | `view` | the product |
| `OptionsRelationManager` set/edit | `manageVariants` | the product |
| `TagsRelationManager` (visible) | `view` | the product |
| `TagsRelationManager` set/remove | `update` | the product |
| `CollectionsRelationManager` (visible) | `view` | the product |
| `CollectionsRelationManager` add/remove | `update` | the product |
| `PublicationsRelationManager` (visible) | `view` | the product |
| `PublicationsRelationManager` publish/reschedule/unpublish | `publish` | the product |
| `CategoryResource` / `CollectionResource` / `BrandResource` / `VendorResource` | Filament's resource defaults → `TaxonomyPolicy` | the record |
| `CategoryResource::moveAction()` | `update` | the category |
| `ProductsRelationManager` (visible) | `view` | the **collection** |
| `ProductsRelationManager` add/remove | `update` | the **collection** |
| `CatalogOverview` | none — `canView()` is `PanelTeam::id() !== null` | — |

`publish` is asked for rather than `update` even though the domain answers both the same way today.
The domain separates them on purpose — editing a description and putting something in front of
shoppers are different-sized mistakes — and a surface that asked for whichever ability was nearest
would silently undo that the day a host makes them differ.

On top of the policies, all five resources narrow their own query to the actor's team, via
`Support\PanelTeam::scope()`. An actor with no team gets `whereRaw('1 = 0')` — **not**
`where('team_id', null)`, which the query builder turns into `is null` and which would list exactly
the unowned records the policies deny everything on.

The scope is read from the actor's `current_team_id`, not from `Filament::getTenant()`, so the plugin
works on a panel with no tenancy configured. `isScopedToTenant()` is `false` on all five resources
for the same reason.

## Writes and the actions behind them

| Surface | Write | Domain action |
| --- | --- | --- |
| `CreateProduct` | create a product | `Actions\CreateProduct` |
| `ProductResource::changeStatusAction()` | move the lifecycle | `Actions\ChangeProductStatus` |
| `ProductResource::setVisibilityAction()` | change visibility | `Actions\SetProductVisibility` |
| `ProductResource::scheduleAvailabilityAction()` | set or clear the window | `Actions\ScheduleAvailability` |
| `VariantsRelationManager` add | add a variant | `Actions\AddVariant` |
| `VariantsRelationManager` remove | delete a variant | `Actions\RemoveVariant` |
| `OptionsRelationManager` set/edit | declare or re-value an axis | `Actions\SetProductOption` |
| `TagsRelationManager` set/remove | set the whole tag set | `Actions\SyncProductTags` |
| `CollectionsRelationManager` / `ProductsRelationManager` add | put a product in a collection | `Actions\AddProductToCollection` |
| `CollectionsRelationManager` / `ProductsRelationManager` remove | take it out | `Actions\RemoveProductFromCollection` |
| `PublicationsRelationManager` publish/reschedule | carry it on a channel, for a window | `Actions\PublishToChannel` |
| `PublicationsRelationManager` unpublish | take it off | `Actions\UnpublishFromChannel` |
| `CreateCategory` | create a category | `Actions\CreateCategory` |
| `CategoryResource::moveAction()` | re-parent a category | `Actions\MoveCategory` |
| `CreateCollection` | create a collection | `Actions\CreateCollection` |
| `CreateBrand` | create a brand | `Actions\CreateBrand` |
| `CreateVendor` | create a vendor | `Actions\CreateVendor` |

The lifecycle select offers exactly `ProductStatus::allowedTransitions()` rather than a transition
table copied into this package, so a release that changes the lifecycle changes this surface with it.
`InvalidStatusTransition` is still caught and shown as a notification: an operator whose record moved
in another tab between render and submit loses that race and deserves a message rather than a 500.
`InvalidAvailabilityWindow`, `SkuAlreadyClaimed` and `CategoryCycle` are handled the same way, each
at the one surface that can raise it.

### Fields deliberately kept out of a form

Where the domain publishes an action, the write goes through it. Where the domain deliberately
publishes none *and* the field carries a rule, the field is absent rather than left to Filament's
default `handleRecordUpdate` — which would write the column and dispatch nothing, the exact bypass
the domain's own docs warn about.

| Field | Where it is not | Why, and where it is instead |
| --- | --- | --- |
| `products.status` | product create and edit forms | The transition table. `ChangeProductStatus` |
| `products.visibility` | product create and edit forms | Not the same fact as status. `SetProductVisibility` |
| `products.available_from` / `available_until` | product create and edit forms | Ordering is validated. `ScheduleAvailability` |
| `products.slug` | everywhere | Derived by `CreateProduct`; editable means every existing URL breaks |
| `products.store_id` | product **edit** form | On the create form only. `CreateProduct` takes it as a named argument so an attribute spread cannot decide who owns the row, and the domain publishes nothing that moves a product between stores |
| `product_variants.sku` | variant **edit** form | `AddVariant` is the only thing that claims a SKU, and it checks the estate-wide uniqueness the index enforces |
| `product_variants.option1..3` | variant edit form | The option values are what the variant *is*, not something about it |
| `product_options.name` | the edit-choices action | `SetProductOption` is keyed on it; editing it would declare a new axis and orphan the old one |
| `product_categories.parent_category_id` | category **edit** form | On the create form only. Re-parenting is `MoveCategory`, which refuses a cycle |
| `product_categories.slug`, `collections.slug`, brand and vendor slugs | everywhere | Derived, unique, and addressable |
| everything except name and parent | category **create** form | `CreateCategory` accepts a name, a parent and a team. A description the form took and the action dropped is a field that lies |

## Known deviation: descriptive fields do not go through a domain action

`EditProduct`, `EditCategory`, `EditCollection`, `EditBrand`, `EditVendor` and the variant edit action
save their descriptive fields through Filament's default rather than through a domain action.

This is not a design preference. The domain package publishes no `UpdateProduct`, `UpdateCategory`,
`UpdateCollection`, `UpdateBrand`, `UpdateVendor` or `UpdateVariant`, so there is nothing to delegate
to. The alternatives were both worse: inventing a local action inside this package would put a second
copy of the domain's update rules in the presentation layer, exactly where they would drift; and
blocking the edit forms would leave an operator unable to fix a typo in a product name.

What keeps the deviation small is that these are precisely the fields the domain has no invariant
about — names, descriptions, meta tags, images, sort positions, a barcode, a weight. Every field that
does carry a rule is in the table above, absent from the form, with its own action.

**Resolved when:** `liberusoftware/ecommerce-catalog` publishes the corresponding `Update*` actions.
At that point each `Edit*` page gains a `handleRecordUpdate()` that delegates, the way `Create*`
already delegates, and this section becomes a changelog entry.

## Known deviation: an option axis cannot be deleted

`OptionsRelationManager` offers no delete action. `SetProductOption` is the only thing the domain
publishes for an option; there is no `RemoveProductOption`.

Deleting the row through Eloquent would leave variants still carrying values on an axis that no
longer exists and would dispatch nothing, so no listener — search indexing, a marketplace feed —
would ever learn that the product's shape changed. An axis with the wrong choices is re-valued
instead, which is the operation the domain does publish.

**Resolved when:** the domain publishes `RemoveProductOption`, at which point a delete action routes
through it.

## Known limitation: a channel is a number

`ProductPublication.channel_id` is rendered as an integer, and the publish form asks for one.

Channels belong to `liberusoftware/ecommerce-commerce-core`, which this package does not depend on.
`ProductPublication::channel()` resolves a class from `config('catalog.channel_model')` and **throws**
when the host has not named one — which is the state of every deployment running the catalogue
without Commerce Core. A column that used the relation would be a panel that crashes on install for
those deployments, so this package never touches it.

A host that does run Commerce Core can subclass `PublicationsRelationManager` and swap the column;
the escape hatch is the ordinary one below.

## Accessibility

- Every action carries a label, including the ones that also carry an icon, so a control Filament
  renders as an icon button still has an accessible name.
- **No state is carried by colour alone.** Product status and visibility render as a badge whose
  **text** is `ProductStatus::label()` / `Visibility::label()`; reachability renders as `Listed` /
  `Link only` / `Not visible` with the reason as a description; a variant's shipping renders as
  `Ships` / `No shipping`; a publication's window renders as `Live` / `Scheduled` / `Ended`. Badge
  colour repeats what the text says. This is why none of these is an `IconColumn::boolean()` — a tick
  and a cross are indistinguishable to a screen reader and to a reader who cannot separate the two
  colours they are drawn in.
- A publication says which of **three** states its window is in. "Not live" would leave an operator
  unable to tell a season staged for next month from one that ended last week.
- Computed and counted columns are labelled rather than left to Filament's humanising, so the heading
  reads `Variants` and `Products` rather than `Variants count` and `Products count`.
- Form fields are labelled, and the qualifications are helper text rather than placeholders standing
  in for a label — `Leave empty to append.`, `Leave empty for "indefinitely".`, `Unique across the
  whole estate, not just this product.`
- Empty values say what they mean rather than rendering as nothing: `Unfiled`, `Always`,
  `Indefinitely`, `Immediately`, `End`, `None`.
- Destructive actions confirm, and the confirmation says what will happen — that removing a variant
  deletes it outright and frees its SKU, that unpublishing a live publication closes it with today's
  date rather than deleting it.
- Every action reports its outcome as a notification, including the ones whose visible effect is a
  row changing. A table redrawing is not feedback an operator who is not watching it can use.

Untested and honest about it: focus order, contrast and keyboard traps are Filament's, not this
package's. This package ships no views and no CSS, so it has neither a way to break them nor a way to
prove them.

## Theme integration

This package ships **no Blade views, no CSS, no JavaScript and no Vite entry point**. Every surface is
built from Filament's own components, so a host theme reaches all of it with no build step here and
nothing to publish.

What the package relies on the host panel to define:

| Relies on | What it is |
| --- | --- |
| Colour aliases `success`, `warning`, `danger`, `gray` | Notifications, and the badge colours on the reachability, shipping and publication-window columns. Whatever the panel's `colors()` maps these to is what renders. |
| The panel's default badge colour | Product status and visibility badges set no colour at all, so they follow the panel. |
| Heroicons (outline) | `heroicon-o-cube`, `heroicon-o-rectangle-group`, `heroicon-o-rectangle-stack`, `heroicon-o-sparkles`, `heroicon-o-truck`, `heroicon-o-arrow-path`, `heroicon-o-eye`, `heroicon-o-calendar-days`, `heroicon-o-arrows-right-left`, `heroicon-o-plus`, `heroicon-o-tag`, `heroicon-o-signal`, `heroicon-o-pencil-square`, `heroicon-o-trash`, `heroicon-o-x-mark`. Filament's default icon set; nothing extra to install. |
| The panel's font, dark mode, spacing and custom theme CSS | Applied by Filament to every component this package renders. |

What a host **can** override without touching this package:

- **Everything visual.** `$panel->colors()`, `->font()`, `->darkMode()`, `->viteTheme()` govern these
  surfaces exactly as they govern the panel's own.
- **Navigation group presentation.** `$panel->navigationGroups([...])` renames, reorders and gives an
  icon to the `Catalog` group these resources sit in.
- **Where the widget appears**, and whether it does — it is a panel widget like any other.

What a host **cannot** override without subclassing:

- The **icon strings** above. They are literal heroicon names, not Filament icon aliases, so
  `FilamentIcon::register()` does not reach them.
- The navigation **labels** and the **group name** (`Catalog`), which are `protected static`
  properties.
- Column sets, form fields and action labels.

The escape hatch for all of those is the ordinary one: extend the resource, override the property or
method, and register your subclass on the panel instead of attaching the plugin. There is no
configuration file and no theming layer here, and adding one to cover a case nobody has hit would be
a second place for these values to live.

## Cache and discovery

`CatalogPlugin::register()` names its five resources and its widget in a literal array. It does not
call `$panel->discoverResources()` or `discoverWidgets()`, and the package has no
`extra.laravel.providers`, so nothing about it is found by scanning.

For an operator this means:

- **`php artisan filament:cache-components` is safe and always has been.** It caches the component
  list so Filament does not re-walk directories at boot; with an explicit registration there is no
  walk to skip, so the command changes nothing about which surfaces appear. Run it or do not.
- **`php artisan optimize` is likewise safe**, and `optimize:clear` cannot make a surface reappear or
  vanish here — there is no discovery state to be stale.
- **A deployment that caches does not need a matching cache-clear step for this package.** Upgrading
  it changes the class list only when the plugin's array changes, and that is loaded from the source
  file either way.
- **`php artisan about` and `filament:upgrade` have nothing to report for this package**, because it
  contributes through a plugin the application attaches rather than through discovery.

The cost of a literal list is that a class added later without being added to the list is simply
absent from the panel: no error, no warning, just a resource nobody can reach.
`tests/Feature/PanelTest.php` asserts the registered set is exactly the classes in `src/Resources` and
`src/Widgets`, so that silence fails a build instead of shipping.
