<?php

declare(strict_types=1);

/**
 * The engine must install without a frontend.
 *
 * `livewire/livewire` is a `suggest`, not a `require`: an API-only consumer
 * (trove + threepio + bloodhound + cennad) classifies through the `Classifier`
 * service and should never be forced to pull in a UI stack to do it.
 *
 * That only holds if nothing outside the `class_exists` guard touches Livewire.
 * Livewire IS installed in this suite, so these check the shape of the code
 * rather than the runtime — the honest test of a condition you cannot easily
 * reproduce in-process.
 */
it('guards every Livewire use in the service provider', function () {
    $provider = file_get_contents(__DIR__.'/../../src/TaxonomyServiceProvider.php');

    // The only executable references are the guard itself and the registration
    // inside it. Imports do not resolve until used, so they are safe.
    $lines = array_values(array_filter(
        explode("\n", $provider),
        fn (string $line): bool => str_contains($line, 'Livewire')
            && ! str_starts_with(trim($line), 'use ')
            && ! str_starts_with(trim($line), '*')
            && ! str_starts_with(trim($line), '//'),
    ));

    // Asserted structurally rather than by line count. The count version broke
    // the moment a second component was registered — a change that should not
    // need this test edited. What matters is that every Livewire use sits
    // INSIDE the guard block, not how many there are.
    expect($lines)->not->toBeEmpty()
        ->and($lines[0])->toContain('class_exists');

    // Walk braces from the guard to find where its block ends, then confirm
    // no Livewire use appears after it.
    $body = explode("\n", $provider);
    $guardLine = null;

    foreach ($body as $i => $line) {
        if (str_contains($line, 'class_exists') && str_contains($line, 'Livewire')) {
            $guardLine = $i;

            break;
        }
    }

    expect($guardLine)->not->toBeNull();

    $depth = 0;
    $closesAt = null;

    for ($i = $guardLine; $i < count($body); $i++) {
        $depth += substr_count($body[$i], '{') - substr_count($body[$i], '}');

        if ($depth === 0 && $i > $guardLine) {
            $closesAt = $i;

            break;
        }
    }

    expect($closesAt)->not->toBeNull();

    for ($i = $closesAt + 1; $i < count($body); $i++) {
        $line = trim($body[$i]);

        if (str_starts_with($line, '*') || str_starts_with($line, '//')) {
            continue;
        }

        expect($line)->not->toContain('Livewire');
    }
});

it('keeps Livewire out of the engine entirely', function () {
    // Only the Livewire directory may reference it. If the Classifier, the
    // Loader or the query service ever did, the suggest would be a lie.
    $leaked = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();

        if (str_contains($path, '/Livewire/') || str_ends_with($path, 'TaxonomyServiceProvider.php')) {
            continue;
        }

        if (str_contains(file_get_contents($path), 'Livewire')) {
            $leaked[] = $file->getFilename();
        }
    }

    expect($leaked)->toBe([]);
});

it('declares Livewire as a suggestion rather than a requirement', function () {
    $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true);

    expect($composer['require'])->not->toHaveKey('livewire/livewire')
        ->and($composer['suggest'])->toHaveKey('livewire/livewire');
});

it('takes no UI-kit dependency', function () {
    // Spec #83: Blade resolves components at compile time, so a class_exists
    // guard around <x-ise::button> still throws where ise is absent. The
    // options are to own the markup or take a hard dependency — this package
    // owns its markup.
    $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), true);

    expect($composer['require'])->not->toHaveKey('marque/ise');

    $views = glob(__DIR__.'/../../resources/views/livewire/*.blade.php') ?: [];

    expect($views)->not->toBeEmpty();

    foreach ($views as $view) {
        expect(file_get_contents($view))->not->toContain('<x-ise::');
    }
});
