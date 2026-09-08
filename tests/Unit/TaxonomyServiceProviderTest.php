<?php

declare(strict_types=1);

use Marque\Taxonomy\TaxonomyServiceProvider;

// CP1 ships no feature code deliberately — these assert the harness itself
// works, which is the thing that goes wrong silently in a new package. The
// provider must be registered under Testbench (no auto-discovery), the config
// must merge, and the database connection must be the testing one rather than
// whatever the host environment would otherwise hand us.
it('registers the service provider', function (): void {
    expect(app()->getProviders(TaxonomyServiceProvider::class))->not->toBeEmpty();
});

it('merges the package config', function (): void {
    expect(config('taxonomy'))->toBeArray()
        ->and(config('taxonomy.definitions.path'))->toBeString();
});

it('runs against the testing connection', function (): void {
    expect(config('database.default'))->toBe('testing');
});
