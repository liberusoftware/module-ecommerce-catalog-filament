# Changelog

## 0.1.0 - 2026-08-09

- First release. A Filament 5 presentation adapter for `liberusoftware/ecommerce-catalog`,
  contributing five resources and a widget to a panel the application composes.
- Products carry relation managers for variants, options, tags, collections and channel publication;
  categories, collections, brands and vendors are their own resources.
- **Reachability.** Status, visibility and the effective dates are three independent facts, and every
  product list leads with what they add up to — `Listed`, `Link only` or `Not visible` — with the
  reason as the badge's description and as the subheading of the edit page. `Support\Reachability`
  computes it from attributes already loaded, and a test asserts it agrees with
  `Product::scopeAvailableOn()` across the whole status × visibility × window matrix.
- Every write the domain publishes an action for goes through it, so the domain events come with
  them: creating a product, category, collection, brand or vendor; moving a product's lifecycle,
  visibility and availability window; adding and removing variants; declaring an axis; setting tags
  by name; collection membership from either end; publishing to a channel and unpublishing.
- Where the domain deliberately publishes no action, the field is kept out of the form rather than
  left to Filament's default. All of them are listed in
  [docs/presentation.md](docs/presentation.md#fields-deliberately-kept-out-of-a-form).
- The lifecycle action offers exactly `ProductStatus::allowedTransitions()`, so this package keeps no
  second copy of a transition table that would drift from the enum's.
- Authorization is `ProductPolicy` and `TaxonomyPolicy` throughout — including `publish`, which the
  domain separates from `update` — and every resource query is scoped to the team the actor is
  working in, the same column the policies read.
- The four models with no policy at all (`ProductVariant`, `ProductOption`, `ProductPublication`,
  `Tag`) are reached only through relation managers, and every action over them gates explicitly on
  the owning product rather than letting an unanswered ability decide.

### Known deviations

- `Edit*` pages and the variant edit action save descriptive fields through Filament's default rather
  than through a domain action, because the domain publishes no `Update*` to delegate to. Recorded in
  [docs/presentation.md](docs/presentation.md#known-deviation-descriptive-fields-do-not-go-through-a-domain-action)
  with the condition that resolves it.
- An option axis cannot be deleted, because the domain publishes no `RemoveProductOption`. Recorded
  in [docs/presentation.md](docs/presentation.md#known-deviation-an-option-axis-cannot-be-deleted).
- A channel is shown as a number, because resolving one would mean depending on a package this one
  deliberately does not. Recorded in
  [docs/presentation.md](docs/presentation.md#known-limitation-a-channel-is-a-number).
