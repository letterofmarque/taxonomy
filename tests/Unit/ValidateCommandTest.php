<?php

declare(strict_types=1);

/**
 * `marque:taxonomy:validate` runs the same checks the loader runs, on demand.
 *
 * The point is that an admin can check a definition BEFORE deploying it,
 * rather than discovering the problem when the tracker refuses to boot. So the
 * command must never be a second, laxer implementation — it delegates to the
 * same Loader.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/marque-taxonomy-cmd-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0777, true);

    config()->set('taxonomy.definitions.path', $this->dir);
});

afterEach(function () {
    array_map(unlink(...), glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

it('passes a valid definition and names what it found', function () {
    file_put_contents($this->dir.'/nfl.yaml', "content_type: nfl_game\nlabel: NFL Game\nlevels:\n  - season: { type: year }\n");

    $this->artisan('marque:taxonomy:validate')
        ->expectsOutputToContain('nfl_game')
        ->assertExitCode(0);
});

it('fails with a non-zero exit code when a definition is invalid', function () {
    // Non-zero matters: this is the command a deploy pipeline runs as a gate,
    // and a gate that always exits 0 gates nothing.
    file_put_contents($this->dir.'/bad.yaml', "content_type: nfl_game\nlabel: NFL\nlevels:\n  - week: { type: nonsense }\n");

    $this->artisan('marque:taxonomy:validate')->assertExitCode(1);
});

it('names the offending file and the reason', function () {
    file_put_contents($this->dir.'/bad.yaml', "content_type: nfl_game\nlabel: NFL\nlevels:\n  - week: { type: nonsense }\n");

    $this->artisan('marque:taxonomy:validate')
        ->expectsOutputToContain('bad.yaml')
        ->expectsOutputToContain('nonsense')
        ->assertExitCode(1);
});

it('succeeds cleanly when there is nothing to validate', function () {
    // No definitions is a legitimate state — a tracker that has not set up a
    // taxonomy yet. Not an error.
    $this->artisan('marque:taxonomy:validate')->assertExitCode(0);
});

it('warns when an override is behind the package version', function () {
    // The shadowed-update report surfaced where an admin will actually see it.
    $packageDir = $this->dir.'-pkg';
    mkdir($packageDir, 0777, true);

    // Declares a migration path because CP5 made that mandatory: a version
    // bump carrying none is now refused outright, so a v2 fixture without one
    // no longer represents anything a package could legitimately ship.
    file_put_contents($packageDir.'/nfl.yaml', "content_type: nfl_game\nlabel: pkg\nversion: 2\nlevels:\n  - season: { type: year }\nmigrations:\n  - from: 1\n    add_facet: [source]\n");
    file_put_contents($this->dir.'/nfl.yaml', "content_type: nfl_game\nlabel: app\nversion: 1\nlevels:\n  - season: { type: year }\n");

    config()->set('taxonomy.definitions.packages', [$packageDir]);

    $this->artisan('marque:taxonomy:validate')
        ->expectsOutputToContain('nfl_game')
        ->assertExitCode(0);

    array_map(unlink(...), glob($packageDir.'/*') ?: []);
    @rmdir($packageDir);
});
