<?php

declare(strict_types=1);

use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Models\Term;
use Marque\Taxonomy\Services\Classifier;
use Marque\Taxonomy\Services\TaxonomyQuery;
use Marque\Taxonomy\Tests\TestUser;
use Marque\Trove\Models\Torrent;

/**
 * The requirement the whole Spec exists for. Dan, on the legacy tracker:
 *
 * > "you could find NFL games on legacy and you got a massive list of them,
 * > you could find NCAA games with the same massive list, you couldn't find
 * > 2006 games or week 12 games without using search (which was just mysql
 * > fulltext, so if you searched for Week 12 and it was wk12 then you were
 * > shit outta luck)."
 *
 * These tests are the answer to that sentence. They run on all four engines,
 * because a query that works only on SQLite proves nothing.
 *
 * The fixture is deliberately built to make the cross-cutting queries
 * meaningful: two leagues, two seasons each, three weeks each. A query for
 * "week 12" must reach across BOTH leagues and BOTH seasons, which is
 * precisely what a depth-based tree cannot express.
 */
beforeEach(function () {
    $this->nfl = ContentType::fromArray([
        'content_type' => 'nfl_game',
        'label' => 'NFL Game',
        'levels' => [
            ['season' => ['type' => 'year']],
            ['week' => ['type' => 'integer', 'range' => [1, 22]]],
        ],
        'facets' => ['resolution', 'subtitles'],
    ]);

    // Structurally different on purpose: NCAA carries a division level NFL
    // does not, so "week" sits at depth 2 here and depth 1 there. This is the
    // exact shape that broke the legacy tracker — 416 week-nodes at one depth,
    // 219 at another.
    $this->ncaa = ContentType::fromArray([
        'content_type' => 'ncaa_game',
        'label' => 'NCAA Game',
        'levels' => [
            ['season' => ['type' => 'year']],
            ['division' => ['type' => 'string']],
            ['week' => ['type' => 'integer', 'range' => [1, 22]]],
        ],
        'facets' => ['resolution'],
    ]);

    $this->classifier = app(Classifier::class);
    $this->query = app(TaxonomyQuery::class);

    $this->user = TestUser::create(['name' => 'u', 'email' => 'u@example.com', 'password' => 'x']);

    $this->make = function (string $name, ContentType $type, array $path, array $facets = []): Torrent {
        static $n = 0;
        $torrent = Torrent::create([
            'info_hash' => str_pad((string) ++$n, 40, '0', STR_PAD_LEFT),
            'name' => $name,
            'user_id' => $this->user->id,
        ]);

        $this->classifier->classify($torrent, $type, $path, $facets);

        return $torrent;
    };

    ($this->make)('NFL 2006 wk12', $this->nfl, ['season' => '2006', 'week' => '12']);
    ($this->make)('NFL 2006 wk4', $this->nfl, ['season' => '2006', 'week' => '4']);
    ($this->make)('NFL 2007 wk12', $this->nfl, ['season' => '2007', 'week' => '12']);
    ($this->make)('NCAA 2006 D1 wk12', $this->ncaa, ['season' => '2006', 'division' => 'D1', 'week' => '12']);
    ($this->make)('NCAA 2007 D1 wk4', $this->ncaa, ['season' => '2007', 'division' => 'D1', 'week' => '4']);
});

describe('the three query shapes CP4 names', function () {
    it('finds every 2006 game across every league', function () {
        // Shape 1: season = 2006 → every league. Crosses content types, which
        // a per-league tree cannot do.
        $results = $this->query->where('season', '2006')->get();

        expect($results->pluck('name')->sort()->values()->all())->toBe([
            'NCAA 2006 D1 wk12',
            'NFL 2006 wk12',
            'NFL 2006 wk4',
        ]);
    });

    it('finds every week 12 game across every season and league', function () {
        // Shape 2, and the one the legacy tracker flatly could not answer.
        // Note `week` is at depth 1 in NFL and depth 2 in NCAA — the query
        // does not care, because a level's identity comes from its
        // declaration, not its position.
        $results = $this->query->where('week', '12')->get();

        expect($results->pluck('name')->sort()->values()->all())->toBe([
            'NCAA 2006 D1 wk12',
            'NFL 2006 wk12',
            'NFL 2007 wk12',
        ]);
    });

    it('finds the specific set when the dimensions are combined', function () {
        // Shape 3: all three together.
        $results = $this->query
            ->contentType('nfl_game')
            ->where('season', '2006')
            ->where('week', '12')
            ->get();

        expect($results->pluck('name')->all())->toBe(['NFL 2006 wk12']);
    });

    it('returns nothing rather than everything when nothing matches', function () {
        // The failure mode worth pinning: an unmatched filter that silently
        // degrades to "no filter" is worse than an empty result.
        expect($this->query->where('week', '19')->get())->toBeEmpty()
            ->and($this->query->where('nonexistent_level', '1')->get())->toBeEmpty();
    });
});

describe('querying down the hierarchy still works', function () {
    it('finds everything under a term, not just its direct children', function () {
        // The materialised path earning its place: descendants by prefix.
        $season = Term::where('content_type', 'nfl_game')
            ->where('level', 'season')->where('value', '2006')->firstOrFail();

        expect($this->query->under($season)->get()->pluck('name')->sort()->values()->all())
            ->toBe(['NFL 2006 wk12', 'NFL 2006 wk4']);
    });

    it('scopes to one content type', function () {
        expect($this->query->contentType('ncaa_game')->get()->pluck('name')->sort()->values()->all())
            ->toBe(['NCAA 2006 D1 wk12', 'NCAA 2007 D1 wk4']);
    });

    it('does not let one content type see another type\'s identically-named level', function () {
        // Acceptance criterion 19: `week` on NFL and `week` on NCAA are
        // independent. Scoping to one type must not leak the other's.
        $results = $this->query->contentType('nfl_game')->where('week', '12')->get();

        expect($results->pluck('name')->sort()->values()->all())
            ->toBe(['NFL 2006 wk12', 'NFL 2007 wk12']);
    });
});

describe('filtering on facets', function () {
    it('finds a torrent by any one of its dozen subtitle values', function () {
        // CP4's second "done when", and acceptance criterion 9. A Netflix-style
        // rip classifies without loss and every value finds it.
        $langs = ['en', 'es', 'fr', 'de', 'ja', 'ko', 'pt', 'it', 'nl', 'pl', 'sv', 'da'];

        $torrent = ($this->make)('Polyglot', $this->nfl, ['season' => '2008'], [
            'subtitles' => $langs,
            'resolution' => ['1080p'],
        ]);

        foreach ($langs as $lang) {
            expect($this->query->withFacet('subtitles', $lang)->get()->pluck('id')->all())
                ->toBe([$torrent->id], "filtering on subtitles={$lang} should find it");
        }
    });

    it('combines a facet filter with a hierarchy filter', function () {
        ($this->make)('NFL 2006 wk12 HD', $this->nfl, ['season' => '2006', 'week' => '12'], [
            'resolution' => ['1080p'],
        ]);

        $results = $this->query
            ->where('week', '12')
            ->withFacet('resolution', '1080p')
            ->get();

        expect($results->pluck('name')->all())->toBe(['NFL 2006 wk12 HD']);
    });

    it('treats two facet filters as AND, not OR', function () {
        ($this->make)('Both', $this->nfl, ['season' => '2009'], [
            'resolution' => ['1080p'],
            'subtitles' => ['en'],
        ]);

        ($this->make)('Only one', $this->nfl, ['season' => '2010'], [
            'resolution' => ['1080p'],
        ]);

        $results = $this->query
            ->withFacet('resolution', '1080p')
            ->withFacet('subtitles', 'en')
            ->get();

        expect($results->pluck('name')->all())->toBe(['Both']);
    });

    it('does not match a value from a different vocabulary', function () {
        // `web` may exist in two vocabularies; filtering on one must not find
        // the other. The facet name is part of the filter, not decoration.
        ($this->make)('Sourced', $this->nfl, ['season' => '2011'], ['resolution' => ['web']]);

        expect($this->query->withFacet('subtitles', 'web')->get())->toBeEmpty();
    });
});

describe('the query returns torrents, not classifications', function () {
    it('hands back Torrent models ready to use', function () {
        $results = $this->query->where('season', '2006')->get();

        expect($results->first())->toBeInstanceOf(Torrent::class)
            ->and($results->first()->info_hash)->not->toBeEmpty();
    });

    it('composes with an existing torrent query rather than replacing it', function () {
        // The seam has to cooperate with trove's own scoping — visibility,
        // dead-torrent filters — or it forces callers to choose between
        // taxonomy and permissions.
        $results = $this->query
            ->where('season', '2006')
            ->applyTo(Torrent::query()->where('name', 'like', 'NFL%'))
            ->get();

        expect($results->pluck('name')->sort()->values()->all())
            ->toBe(['NFL 2006 wk12', 'NFL 2006 wk4']);
    });

    it('does not return the same torrent twice when several facets match', function () {
        // The classic join-fanout bug: two matching assignment rows producing
        // two identical result rows.
        $torrent = ($this->make)('Multi', $this->nfl, ['season' => '2012'], [
            'subtitles' => ['en', 'es', 'fr'],
        ]);

        expect($this->query->contentType('nfl_game')->where('season', '2012')->get())->toHaveCount(1);
    });
});
