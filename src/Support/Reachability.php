<?php

namespace Liberu\Ecommerce\Catalog\Filament\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Liberu\Ecommerce\Catalog\Models\Product;
use Liberu\Ecommerce\Catalog\Models\ProductPublication;

/**
 * Whether a shopper can see this product right now, and if not, why not.
 *
 * Status, visibility and the effective dates are three independent facts, and
 * the domain keeps them apart on purpose. That is right, and it leaves an
 * operator with three switches and no answer to the only question they came to
 * ask. `Product::scopeAvailableOn()` answers it — but it answers yes or no, and
 * a screen that can only say "no" is the screen that generates the support
 * ticket. This says which of the three facts is the reason.
 *
 * That means this class decomposes a rule the domain also states, so the one
 * thing that matters is that the two never disagree. `tests/Feature/ReachabilityTest.php`
 * asserts across the whole status × visibility × window matrix that `reachable`
 * equals `Product::isAvailableOn()` and `listed` equals `Product::isListedOn()`.
 * If a release changes the rule and forgets this file, that test fails rather
 * than the panel quietly lying.
 *
 * Pure PHP over attributes already loaded, so a table of fifty products costs no
 * queries — unlike `isAvailableOn()`, which costs one per call and is documented
 * as such.
 *
 * Catalogue-wide, not per channel. A publication is a fourth fact with its own
 * window and it is per channel, so it cannot collapse into one verdict; the
 * count of live publications is reported alongside instead, and the per-channel
 * detail lives in the publications relation manager.
 */
final class Reachability
{
    /**
     * @param  list<string>  $blockers  Why no shopper can reach it. Empty when they can.
     * @param  int|null  $liveChannels  Null when the publications relation was not loaded.
     */
    private function __construct(
        public readonly bool $reachable,
        public readonly bool $listed,
        public readonly array $blockers,
        public readonly ?int $liveChannels,
    ) {}

    public static function of(Product $product, ?DateTimeInterface $at = null): self
    {
        $at ??= CarbonImmutable::now();

        $blockers = [];

        if (! $product->status->isSellable()) {
            $blockers[] = 'its status is '.$product->status->label().' rather than Active';
        }

        // `isReachable()` rather than `isListed()`: unlisted is deliberately
        // reachable by direct link, and treating it as a blocker here would
        // erase the only difference between the two states.
        if (! $product->visibility->isReachable()) {
            $blockers[] = 'its visibility is '.$product->visibility->label();
        }

        $from = $product->available_from;
        $until = $product->available_until;

        if ($from !== null && $from > $at) {
            $blockers[] = 'it is not available until '.$from->toDayDateTimeString();
        }

        // `<=` matches the domain scope's `available_until > $at` exactly. An
        // off-by-one here would be a product the panel calls live and the
        // storefront 404s.
        if ($until !== null && $until <= $at) {
            $blockers[] = 'its availability ended on '.$until->toDayDateTimeString();
        }

        return new self(
            reachable: $blockers === [],
            listed: $blockers === [] && $product->visibility->isListed(),
            blockers: $blockers,
            liveChannels: self::liveChannels($product, $at),
        );
    }

    /** Three words, never a colour or an icon on its own. */
    public function label(): string
    {
        return match (true) {
            $this->listed => 'Listed',
            $this->reachable => 'Link only',
            default => 'Not visible',
        };
    }

    /**
     * Colour that repeats the label rather than carrying it.
     */
    public function color(): string
    {
        return match (true) {
            $this->listed => 'success',
            $this->reachable => 'warning',
            default => 'danger',
        };
    }

    /** The whole answer, as a sentence an operator can act on. */
    public function summary(): string
    {
        $verdict = match (true) {
            $this->listed => 'Shoppers can find this product in listings and search.',
            $this->reachable => 'Reachable by direct link only, because its visibility is Unlisted: it is absent from listings, search and feeds.',
            default => 'No shopper can see this product, because '.Arr::join($this->blockers, ', ', ' and ').'.',
        };

        return $verdict.$this->channelNote();
    }

    private function channelNote(): string
    {
        if ($this->liveChannels === null) {
            return '';
        }

        // Stated rather than folded into the verdict. A host running one
        // storefront with no channels needs no publication at all, so "on no
        // channel" is a fact about this product and not a fault in it.
        if ($this->liveChannels === 0) {
            return ' It is published on no channel, so any storefront that asks for one will not carry it.';
        }

        return ' It is published on '.$this->liveChannels.' '.Str::plural('channel', $this->liveChannels).'.';
    }

    private static function liveChannels(Product $product, DateTimeInterface $at): ?int
    {
        // Never lazily loaded. A column that fetched the relation per row would
        // turn a page of products into a query per product, which is the exact
        // cost this class exists to avoid.
        if (! $product->relationLoaded('publications')) {
            return null;
        }

        return $product->publications
            ->filter(fn (ProductPublication $publication): bool => $publication->isLive($at))
            ->count();
    }
}
