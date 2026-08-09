<?php

namespace Liberu\Ecommerce\Catalog\Filament;

use Illuminate\Support\ServiceProvider;

/**
 * Named by `module.json` and registered by `ModuleManagerServiceProvider`, never
 * by Composer discovery — the package ships no `extra.laravel.providers`.
 *
 * It has nothing to do. The panels belong to the application, and this package
 * contributes to them through {@see CatalogPlugin}, which the application
 * attaches. A provider that reached into a panel here would register this
 * module's resources into panels that never asked for them.
 */
final class CatalogFilamentServiceProvider extends ServiceProvider {}
