<?php

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Liberu\Ecommerce\Catalog\Filament\Support\Instant;

/**
 * `Instant` is three lines and every one of them is a branch that decides
 * whether a window has a bound. The empty-string case is the one that matters:
 * a parser that accepted it would set a product's availability to 1970 and
 * nothing would report an error.
 */
it('reads an empty date field as no bound rather than as the epoch', function () {
    expect(Instant::from(null))->toBeNull()
        ->and(Instant::from(''))->toBeNull()
        ->and(Instant::from('   '))->toBeNull()
        // Not a string and not a date — a crafted payload, refused rather than
        // coerced into whatever `CarbonImmutable::parse()` makes of it.
        ->and(Instant::from(['2026-06-01']))->toBeNull()
        ->and(Instant::from(true))->toBeNull();
});

it('accepts both shapes a schema hands back', function () {
    // A submitted picker gives a string; `fillForm()` puts the model's own
    // immutable date into the schema, and a component that dehydrates it
    // unchanged hands back the object.
    expect(Instant::from('2026-06-01 12:00:00')?->toDateTimeString())->toBe('2026-06-01 12:00:00')
        ->and(Instant::from(new DateTimeImmutable('2026-06-01 12:00:00')))->toBeInstanceOf(CarbonImmutable::class)
        ->and(Instant::from(new DateTimeImmutable('2026-06-01 12:00:00'))?->toDateTimeString())->toBe('2026-06-01 12:00:00')
        ->and(Instant::from(CarbonImmutable::parse('2026-06-01 12:00:00'))?->toDateTimeString())->toBe('2026-06-01 12:00:00');
});
