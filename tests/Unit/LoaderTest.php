<?php

declare(strict_types=1);

use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Exceptions\InvalidDefinitionException;

// Real directories in a temp dir rather than a virtual filesystem: the loader's
// job is reading actual files off disk, and mocking that away would test the
// mock. Cheap enough that the honesty is free.
beforeEach(function () {
    $base = sys_get_temp_dir().'/marque-taxonomy-'.bin2hex(random_bytes(6));

    $this->appDir = $base.'/app';
    $this->pkgDir = $base.'/pkg';

    mkdir($this->appDir, 0777, true);
    mkdir($this->pkgDir, 0777, true);
});

afterEach(function () {
    foreach ([$this->appDir, $this->pkgDir] as $dir) {
        array_map(unlink(...), glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    @rmdir(dirname($this->appDir));
});

function writeDefinition(string $dir, string $file, string $yaml): void
{
    file_put_contents($dir.'/'.$file, $yaml);
}

describe('round-tripping a definition', function () {
    it('reads YAML into a definition object', function () {
        // CP3's first "done when": a content type round-trips from YAML to
        // definition object.
        writeDefinition($this->pkgDir, 'nfl_game.yaml', <<<'YAML'
        content_type: nfl_game
        label: NFL Game
        version: 1
        levels:
          - season: { label: Season, type: year }
          - week: { label: Week, type: integer, range: [1, 22] }
        facets: [resolution, source]
        YAML);

        $types = (new Loader([$this->pkgDir], $this->appDir))->load();

        expect($types)->toHaveKey('nfl_game')
            ->and($types['nfl_game'])->toBeInstanceOf(ContentType::class)
            ->and($types['nfl_game']->levelNames())->toBe(['season', 'week'])
            ->and($types['nfl_game']->facets)->toBe(['resolution', 'source'])
            ->and($types['nfl_game']->level('week')->max)->toBe(22);
    });

    it('reads .yml as well as .yaml', function () {
        // Both extensions are in common use and an admin should not have to
        // discover which one we happened to pick.
        writeDefinition($this->pkgDir, 'cricket.yml', <<<'YAML'
        content_type: cricket_match
        label: Cricket Match
        levels:
          - format: { label: Format, type: string }
        YAML);

        expect((new Loader([$this->pkgDir], $this->appDir))->load())->toHaveKey('cricket_match');
    });

    it('loads several content types from one directory', function () {
        // The multi-domain case the whole Spec is written against: adding
        // cricket alongside NFL is a file, not a fork.
        writeDefinition($this->pkgDir, 'nfl.yaml', "content_type: nfl_game\nlabel: NFL\nlevels:\n  - season: { type: year }\n");
        writeDefinition($this->pkgDir, 'cricket.yaml', "content_type: cricket_match\nlabel: Cricket\nlevels:\n  - format: { type: string }\n");

        expect((new Loader([$this->pkgDir], $this->appDir))->load())
            ->toHaveKeys(['nfl_game', 'cricket_match']);
    });

    it('returns nothing when there is nothing to load', function () {
        expect((new Loader([$this->pkgDir], $this->appDir))->load())->toBe([]);
    });

    it('ignores a missing directory rather than failing', function () {
        // A tracker with no app-level overrides has no config/taxonomies
        // directory, and that is the normal case, not an error.
        expect((new Loader([base_path('does-not-exist')], base_path('nor-this')))->load())->toBe([]);
    });
});

describe('precedence — the app wins', function () {
    it('lets an app definition override a package one of the same name', function () {
        // CP3's third "done when", and Spec #103's Decision: "definitions load
        // from both packages and the app; the app wins."
        writeDefinition($this->pkgDir, 'nfl.yaml', <<<'YAML'
        content_type: nfl_game
        label: NFL Game (package)
        version: 1
        levels:
          - season: { type: year }
        YAML);

        writeDefinition($this->appDir, 'nfl.yaml', <<<'YAML'
        content_type: nfl_game
        label: NFL Game (mine)
        version: 1
        levels:
          - season: { type: year }
          - week: { type: integer, range: [1, 22] }
        YAML);

        $types = (new Loader([$this->pkgDir], $this->appDir))->load();

        expect($types['nfl_game']->label)->toBe('NFL Game (mine)')
            ->and($types['nfl_game']->levelNames())->toBe(['season', 'week']);
    });

    it('matches on content type name, not on filename', function () {
        // The override key is the content_type inside the file. Two files
        // named differently that declare the same type still collide, and the
        // app's still wins.
        writeDefinition($this->pkgDir, 'a-package-file.yaml', "content_type: nfl_game\nlabel: package\nlevels:\n  - season: { type: year }\n");
        writeDefinition($this->appDir, 'totally-different-name.yaml', "content_type: nfl_game\nlabel: app\nlevels:\n  - season: { type: year }\n");

        expect((new Loader([$this->pkgDir], $this->appDir))->load()['nfl_game']->label)->toBe('app');
    });

    it('reports that an override is shadowing a newer package version', function () {
        // "A package update never silently changes a definition the admin has
        // overridden — but the admin is told a newer version exists."
        writeDefinition($this->pkgDir, 'nfl.yaml', "content_type: nfl_game\nlabel: pkg\nversion: 2\nlevels:\n  - season: { type: year }\nmigrations:\n  - from: 1\n    add_facet: [source]\n");
        writeDefinition($this->appDir, 'nfl.yaml', "content_type: nfl_game\nlabel: app\nversion: 1\nlevels:\n  - season: { type: year }\n");

        $loader = new Loader([$this->pkgDir], $this->appDir);
        $types = $loader->load();

        // The app's definition is the one in force...
        expect($types['nfl_game']->label)->toBe('app')
            ->and($types['nfl_game']->version)->toBe(1);

        // ...and the admin is told, rather than the update applying silently.
        expect($loader->shadowedUpdates())->toHaveCount(1)
            ->and($loader->shadowedUpdates()[0]['content_type'])->toBe('nfl_game')
            ->and($loader->shadowedUpdates()[0]['app_version'])->toBe(1)
            ->and($loader->shadowedUpdates()[0]['package_version'])->toBe(2);
    });

    it('says nothing when the override is not behind', function () {
        writeDefinition($this->pkgDir, 'nfl.yaml', "content_type: nfl_game\nlabel: pkg\nversion: 1\nlevels:\n  - season: { type: year }\n");
        writeDefinition($this->appDir, 'nfl.yaml', "content_type: nfl_game\nlabel: app\nversion: 1\nlevels:\n  - season: { type: year }\n");

        $loader = new Loader([$this->pkgDir], $this->appDir);
        $loader->load();

        expect($loader->shadowedUpdates())->toBe([]);
    });
});

describe('a bad file is rejected whole', function () {
    it('throws rather than returning a half-built definition', function () {
        // The hard requirement. "A definition loads completely or not at all."
        writeDefinition($this->pkgDir, 'broken.yaml', <<<'YAML'
        content_type: nfl_game
        label: NFL Game
        levels:
          - week: { type: nonsense }
        YAML);

        expect(fn () => (new Loader([$this->pkgDir], $this->appDir))->load())
            ->toThrow(InvalidDefinitionException::class);
    });

    it('names the file and the problem in the message', function () {
        // An admin who cannot tell which file broke has a catalogue they
        // cannot diagnose, which is the failure this rule exists to prevent.
        writeDefinition($this->pkgDir, 'broken.yaml', "content_type: nfl_game\nlabel: NFL\nlevels:\n  - week: { type: nonsense }\n");

        try {
            (new Loader([$this->pkgDir], $this->appDir))->load();
            $this->fail('Expected InvalidDefinitionException.');
        } catch (InvalidDefinitionException $e) {
            expect($e->getMessage())->toContain('broken.yaml')
                ->and($e->getMessage())->toContain('nonsense');
        }
    });

    it('rejects malformed YAML with the file named', function () {
        // A syntax error is not a validation error and takes a different path,
        // but the admin experience must be identical.
        writeDefinition($this->pkgDir, 'syntax.yaml', "content_type: nfl\n  bad indent: [unclosed\n");

        try {
            (new Loader([$this->pkgDir], $this->appDir))->load();
            $this->fail('Expected InvalidDefinitionException.');
        } catch (InvalidDefinitionException $e) {
            expect($e->getMessage())->toContain('syntax.yaml');
        }
    });

    it('rejects a file whose YAML is not a map', function () {
        writeDefinition($this->pkgDir, 'list.yaml', "- just\n- a list\n");

        expect(fn () => (new Loader([$this->pkgDir], $this->appDir))->load())
            ->toThrow(InvalidDefinitionException::class);
    });

    it('loses the good definitions too, so nothing half-applies', function () {
        // The whole point: one bad file does not leave a tracker running on a
        // partial taxonomy. It leaves it running on its PREVIOUS definitions,
        // which means the load as a whole must fail.
        writeDefinition($this->pkgDir, 'good.yaml', "content_type: fine\nlabel: Fine\nlevels:\n  - a: { type: string }\n");
        writeDefinition($this->pkgDir, 'bad.yaml', "content_type: broken\nlabel: Broken\nlevels:\n  - b: { type: nonsense }\n");

        expect(fn () => (new Loader([$this->pkgDir], $this->appDir))->load())
            ->toThrow(InvalidDefinitionException::class);
    });

    it('rejects two files declaring the same content type in one source', function () {
        // Across sources this is an override and legitimate. Within one
        // source it is ambiguous — which file wins is undefined, so refuse.
        writeDefinition($this->pkgDir, 'one.yaml', "content_type: nfl_game\nlabel: One\nlevels:\n  - a: { type: string }\n");
        writeDefinition($this->pkgDir, 'two.yaml', "content_type: nfl_game\nlabel: Two\nlevels:\n  - a: { type: string }\n");

        expect(fn () => (new Loader([$this->pkgDir], $this->appDir))->load())
            ->toThrow(InvalidDefinitionException::class);
    });
});

describe('the container binding', function () {
    it('sees a configuration change rather than caching the old paths', function () {
        // Registered as a bind, not a singleton. A singleton captures the
        // configured paths at construction, so a later change to
        // `taxonomy.definitions.path` is invisible and the tracker silently
        // runs on stale definitions — a nasty thing to diagnose, and exactly
        // the staleness the no-cache decision exists to avoid.
        writeDefinition($this->pkgDir, 'a.yaml', "content_type: first\nlabel: First\nlevels:\n  - a: { type: string }\n");
        config()->set('taxonomy.definitions.path', $this->pkgDir);

        expect(app(Loader::class)->load())->toHaveKey('first');

        writeDefinition($this->appDir, 'b.yaml', "content_type: second\nlabel: Second\nlevels:\n  - b: { type: string }\n");
        config()->set('taxonomy.definitions.path', $this->appDir);

        expect(app(Loader::class)->load())->toHaveKey('second');
    });
});
