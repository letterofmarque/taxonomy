<?php

declare(strict_types=1);

use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Exceptions\InvalidClassificationException;
use Marque\Taxonomy\Models\Term;
use Marque\Taxonomy\Services\Classifier;
use Marque\Taxonomy\Services\TaxonomyQuery;
use Marque\Taxonomy\Tests\TestUser;
use Marque\Trove\Models\Torrent;

/**
 * The Spec's central claim, tested rather than asserted.
 *
 * > Run a multi-sport tracker at [Gazelle] — NFL, cricket, swimming, cycling.
 * > Cricket has innings and Test/ODI/T20. Swimming has events, heats, semis and
 * > finals. Cycling has stages and classifications. On Gazelle that is schema
 * > changes, a rewritten upload form, and a rewritten search builder, per sport.
 * > It is a fork, not a configuration.
 * >
 * > **Marque should answer that with a YAML file per sport.**
 *
 * Four content types load from `tests/fixtures/taxonomies/`: NFL (season →
 * week), cricket (season → format → innings), TV (series → season → episode)
 * and cycling (season → race → stage). Different depths, different leaf
 * meanings, and `season` declared by all four.
 *
 * **TV is in there on purpose.** A fixture set made only of sports could hide
 * domain leakage, because everything would happen to share a vocabulary.
 *
 * Everything here runs against files. No PHP was written for any of these four
 * domains, which is the claim.
 */
beforeEach(function () {
    $this->types = (new Loader([__DIR__.'/../fixtures/taxonomies']))->load();

    $this->classifier = app(Classifier::class);
    $this->query = app(TaxonomyQuery::class);
    $this->user = TestUser::create(['name' => 'u', 'email' => 'u@example.com', 'password' => 'x']);

    $this->make = function (string $name, string $type, array $path, array $facets = []): Torrent {
        static $n = 0;

        $torrent = Torrent::create([
            'info_hash' => str_pad((string) ++$n, 40, '0', STR_PAD_LEFT),
            'name' => $name,
            'user_id' => $this->user->id,
        ]);

        $this->classifier->classify($torrent, $this->types[$type], $path, $facets);

        return $torrent;
    };
});

describe('four domains, one engine', function () {
    it('loads every content type from YAML alone', function () {
        // Acceptance criterion 1: a new content type is one YAML file, no
        // migration, no deploy, no PHP.
        expect($this->types)->toHaveKeys(['nfl_game', 'cricket_match', 'tv_episode', 'cycling_stage']);
    });

    it('gives each domain its own shape and depth', function () {
        expect($this->types['nfl_game']->levelNames())->toBe(['season', 'week'])
            ->and($this->types['cricket_match']->levelNames())->toBe(['season', 'format', 'innings'])
            ->and($this->types['tv_episode']->levelNames())->toBe(['series', 'season', 'episode'])
            ->and($this->types['cycling_stage']->levelNames())->toBe(['season', 'race', 'stage']);
    });

    it('classifies into all four without the engine knowing what any of them are', function () {
        ($this->make)('Eagles at Cowboys', 'nfl_game', ['season' => '2006', 'week' => '12']);
        ($this->make)('Ashes 2nd Test', 'cricket_match', ['season' => '2006', 'format' => 'Test', 'innings' => '2']);
        ($this->make)('Show S03E05', 'tv_episode', ['series' => 'A Show', 'season' => '3', 'episode' => '5']);
        ($this->make)('TdF Stage 14', 'cycling_stage', ['season' => '2006', 'race' => 'Tour de France', 'stage' => '14']);

        expect(Torrent::count())->toBe(4);
    });
});

describe('acceptance criterion 3 — identically-named levels do not collide', function () {
    it('keeps four different meanings of season apart', function () {
        // NFL's "the 2006 season", cricket's 2006, TV's "season 3 of a show",
        // cycling's 2006. Same word, four unrelated things.
        ($this->make)('NFL', 'nfl_game', ['season' => '2006', 'week' => '12']);
        ($this->make)('Cricket', 'cricket_match', ['season' => '2006', 'format' => 'Test', 'innings' => '2']);
        ($this->make)('TV', 'tv_episode', ['series' => 'A Show', 'season' => '3', 'episode' => '5']);
        ($this->make)('Cycling', 'cycling_stage', ['season' => '2006', 'race' => 'TdF', 'stage' => '14']);

        // Four separate season terms — one per content type — not one shared.
        expect(Term::where('level', 'season')->count())->toBe(4)
            ->and(Term::where('level', 'season')->distinct()->pluck('content_type')->sort()->values()->all())
            ->toBe(['cricket_match', 'cycling_stage', 'nfl_game', 'tv_episode']);
    });

    it('scopes a query to one domain even when the level name is shared', function () {
        ($this->make)('NFL 2006', 'nfl_game', ['season' => '2006', 'week' => '12']);
        ($this->make)('Cricket 2006', 'cricket_match', ['season' => '2006', 'format' => 'Test', 'innings' => '2']);
        ($this->make)('Cycling 2006', 'cycling_stage', ['season' => '2006', 'race' => 'TdF', 'stage' => '14']);

        expect($this->query->contentType('nfl_game')->where('season', '2006')->get()->pluck('name')->all())
            ->toBe(['NFL 2006']);
    });

    it('still crosses domains when not scoped, which is the CP4 promise', function () {
        // Unscoped, `season = 2006` deliberately spans every domain that has
        // one — three of the four here, since TV's season 3 is not a year.
        ($this->make)('NFL 2006', 'nfl_game', ['season' => '2006', 'week' => '12']);
        ($this->make)('Cricket 2006', 'cricket_match', ['season' => '2006', 'format' => 'Test', 'innings' => '2']);
        ($this->make)('Cycling 2006', 'cycling_stage', ['season' => '2006', 'race' => 'TdF', 'stage' => '14']);
        ($this->make)('TV S03', 'tv_episode', ['series' => 'A Show', 'season' => '3', 'episode' => '5']);

        expect($this->query->where('season', '2006')->get()->pluck('name')->sort()->values()->all())
            ->toBe(['Cricket 2006', 'Cycling 2006', 'NFL 2006']);
    });

    it('does not let one domain reach another domain\'s leaf level', function () {
        // `innings` belongs to cricket. An NFL uploader has no such field, and
        // asking for one is an error rather than a silent no-op.
        expect(fn () => ($this->make)('Wrong', 'nfl_game', ['season' => '2006', 'innings' => '2']))
            ->toThrow(InvalidClassificationException::class);
    });

    it('applies each domain\'s own range to its own levels', function () {
        // Cricket innings max 4, cycling stages max 21. The same number is
        // valid in one and not the other, which only works because ranges are
        // scoped to the declaring content type.
        ($this->make)('Stage 14', 'cycling_stage', ['season' => '2006', 'race' => 'TdF', 'stage' => '14']);

        expect(fn () => ($this->make)('Innings 14', 'cricket_match', [
            'season' => '2006', 'format' => 'Test', 'innings' => '14',
        ]))->toThrow(InvalidClassificationException::class);
    });
});

describe('acceptance criterion 8 — core knows no domain vocabulary', function () {
    it('has no domain word anywhere in the engine source', function () {
        // The blunt version of the criterion, and the one that would actually
        // catch a leak: grep the shipped source for the vocabulary these
        // fixtures use. If the engine ever learns what a season is, this fails.
        $source = '';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $source .= file_get_contents($file->getPathname());
            }
        }

        // Strip comments before searching: the docblocks explain the design
        // using these very examples, which is documentation rather than
        // behaviour. What matters is that no CODE branches on them.
        $code = implode(' ', array_map(
            fn (array|string $token): string => is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                : $token,
            token_get_all($source),
        ));

        // Collected and asserted once rather than one expect() per word.
        // `toContain` is variadic — a second argument is another needle, not a
        // failure message — so passing an explanation there silently weakened
        // the assertion into "contains neither the word nor the message",
        // which a planted leak passed straight through. Caught by
        // mutation-testing this very guard.
        $found = array_values(array_filter(
            ['season', 'week', 'innings', 'episode', 'series', 'stage', 'race', 'nfl', 'cricket', 'sport'],
            fn (string $word): bool => str_contains(strtolower($code), $word),
        ));

        expect($found)->toBe([]);
    });

    it('ships no definitions of its own', function () {
        // The engine's own config points at the app's directory and nothing
        // else. A domain package supplies definitions; core supplies none.
        $shipped = glob(__DIR__.'/../../config/*.yaml') ?: [];

        expect($shipped)->toBe([]);
    });
});

describe('the honest question — did adding a domain touch the engine?', function () {
    it('classifies and queries a domain the engine has never seen, with no code change', function () {
        // Swimming: heats, semis, finals. Named in the Spec as a case Gazelle
        // cannot express without a fork. It has no fixture file and no PHP —
        // built here, at runtime, from a definition written in this test.
        $swimming = ContentType::fromArray([
            'content_type' => 'swimming_event',
            'label' => 'Swimming Event',
            'levels' => [
                ['meet' => ['label' => 'Meet', 'type' => 'string']],
                ['event' => ['label' => 'Event', 'type' => 'string']],
                ['round' => ['label' => 'Round', 'type' => 'string']],
            ],
            'facets' => ['resolution'],
        ]);

        $torrent = Torrent::create([
            'info_hash' => str_repeat('f', 40),
            'name' => '100m Free Final',
            'user_id' => $this->user->id,
        ]);

        $this->classifier->classify($torrent, $swimming, [
            'meet' => 'World Championships',
            'event' => '100m Freestyle',
            'round' => 'Final',
        ], ['resolution' => ['1080p']]);

        // And it is immediately queryable by the same engine, on the same
        // cross-cutting terms, alongside the four file-defined domains.
        expect($this->query->contentType('swimming_event')->where('round', 'Final')->get()->pluck('name')->all())
            ->toBe(['100m Free Final']);
    });

    it('answers a cross-domain question none of the domains know about', function () {
        // "Everything at 1080p", spanning four unrelated domains. No domain
        // package coordinated this; the shared facet vocabulary is what makes
        // it work.
        ($this->make)('NFL', 'nfl_game', ['season' => '2006', 'week' => '12'], ['resolution' => ['1080p']]);
        ($this->make)('Cricket', 'cricket_match', ['season' => '2006', 'format' => 'Test', 'innings' => '2'], ['resolution' => ['1080p']]);
        ($this->make)('TV', 'tv_episode', ['series' => 'A Show', 'season' => '3', 'episode' => '5'], ['resolution' => ['720p']]);
        ($this->make)('Cycling', 'cycling_stage', ['season' => '2006', 'race' => 'TdF', 'stage' => '14'], ['resolution' => ['1080p']]);

        expect($this->query->withFacet('resolution', '1080p')->get()->pluck('name')->sort()->values()->all())
            ->toBe(['Cricket', 'Cycling', 'NFL']);
    });
});
