<?php

namespace Liberu\Ecommerce\Catalog\Filament\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * What a date-time field submitted, as something the domain actions accept.
 *
 * Two shapes arrive at the same place. `fillForm()` puts the model's own
 * `CarbonImmutable` into the schema, and a submitted picker hands back a string
 * — or an empty one, which means "no bound" and must become null rather than
 * `1970-01-01`. Both windows in this package go through the same three lines
 * because a second copy is where one of them would eventually parse an empty
 * string.
 */
final class Instant
{
    public static function from(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
