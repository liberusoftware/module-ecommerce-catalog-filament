<?php

namespace Liberu\Ecommerce\Catalog\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Liberu\Ecommerce\Catalog\Filament\Support\PanelTeam;
use Liberu\Ecommerce\Catalog\Models\Category;
use Liberu\Ecommerce\Catalog\Models\Product;

/**
 * How much of this merchant's catalogue a shopper can actually see.
 *
 * "Listed" and "reachable" are asked of the domain's own scopes rather than
 * counted as `status = 'active'` here. There are three independent facts behind
 * that answer and a dashboard that counted one of them would report a catalogue
 * as live while most of it was hidden or out of date.
 *
 * The middle stat is the one worth having: a merchant who has just imported six
 * hundred products wants the number that is going nowhere, not the number that
 * exists.
 */
class CatalogOverview extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return PanelTeam::id() !== null;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $products = PanelTeam::scope(Product::query());
        $categories = PanelTeam::scope(Category::query());

        $total = $products->clone()->count();
        // `scopes()` rather than `->listedOn()`: the scope is a method on the
        // model, and calling it straight off a builder is invisible to static
        // analysis even where it works.
        $listed = $products->clone()->scopes('listedOn')->count();
        $reachable = $products->clone()->scopes('availableOn')->count();

        return [
            Stat::make('Products', (string) $total)
                ->description($listed.' listed to shoppers'),
            Stat::make('Not reachable', (string) ($total - $reachable))
                ->description('no shopper can see these at all'),
            Stat::make('Categories', (string) $categories->count()),
        ];
    }
}
