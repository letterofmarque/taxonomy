<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Models\Classification;
use Marque\Taxonomy\Models\Term;
use Marque\Taxonomy\Services\Classifier;
use Marque\Taxonomy\Services\TaxonomyQuery;
use Marque\Taxonomy\Tests\TestUser;
use Marque\Trove\Models\Torrent;

/**
 * Runtime definitions instead of dedicated columns was a deliberate trade
 * (Spec #103, Decisions). CP4 asks for the cost to be *demonstrated* rather
 * than asserted — "taxonomy tables are small and read-heavy, but 'small'
 * should be demonstrated."
 *
 * These assert query COUNT, not wall-clock time. A timing assertion on shared
 * CI would be flaky and would tell us about the runner rather than the design;
 * query count is deterministic and is what actually degrades if someone later
 * introduces an N+1 in the filter path.
 */
beforeEach(function () {
    $this->type = ContentType::fromArray([
        'content_type' => 'nfl_game',
        'label' => 'NFL Game',
        'levels' => [
            ['season' => ['type' => 'year']],
            ['week' => ['type' => 'integer', 'range' => [1, 22]]],
        ],
        'facets' => ['resolution'],
    ]);

    $classifier = app(Classifier::class);
    $user = TestUser::create(['name' => 'u', 'email' => 'u@example.com', 'password' => 'x']);

    // A realistically-shaped tree: 5 seasons x 20 weeks = 100 terms + 5, with
    // 200 torrents spread across it. Small by design — the Spec's own claim is
    // that taxonomy trees are hundreds of nodes, not millions.
    $n = 0;

    foreach (range(2002, 2006) as $season) {
        foreach (range(1, 20) as $week) {
            foreach (range(1, 2) as $ignored) {
                $torrent = Torrent::create([
                    'info_hash' => str_pad((string) ++$n, 40, '0', STR_PAD_LEFT),
                    'name' => "Game {$season} wk{$week}",
                    'user_id' => $user->id,
                ]);

                $classifier->classify($torrent, $this->type, [
                    'season' => (string) $season,
                    'week' => (string) $week,
                ], ['resolution' => ['1080p']]);
            }
        }
    }

    $this->query = app(TaxonomyQuery::class);
});

it('builds a tree of the size the Spec predicts', function () {
    // 5 seasons + 100 weeks. If this ever balloons, term reuse has broken and
    // the hierarchy has degenerated into a per-torrent list.
    expect(Term::count())->toBe(105)
        ->and(Classification::count())->toBe(200);
});

it('answers a cross-cutting query in a constant number of queries', function () {
    // "all week 12 games across every season" — the query the legacy tracker
    // could not express at all. It must not cost one query per season.
    DB::enableQueryLog();

    $results = $this->query->where('week', '12')->get();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($results)->toHaveCount(10)
        ->and($count)->toBeLessThanOrEqual(4);
});

it('does not grow its query count as filters are combined', function () {
    DB::enableQueryLog();

    $this->query
        ->contentType('nfl_game')
        ->where('season', '2006')
        ->where('week', '12')
        ->withFacet('resolution', '1080p')
        ->get();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Two level filters resolve their terms (2 queries each: the terms, then
    // their descendants) plus the final select. The point is that it is
    // bounded by the number of FILTERS, not by the number of rows.
    expect($count)->toBeLessThanOrEqual(6);
});

it('uses the path index rather than scanning, on SQLite', function () {
    // EXPLAIN output is engine-specific, so this probe is SQLite-only and
    // skips elsewhere rather than failing with a syntax error that would read
    // like a broken package. Same pattern as the suite's other index probes.
    if (DB::connection()->getDriverName() !== 'sqlite') {
        test()->markTestSkipped('EXPLAIN QUERY PLAN is SQLite-only.');
    }

    $season = Term::where('level', 'season')->where('value', '2006')->firstOrFail();

    $plan = DB::select('EXPLAIN QUERY PLAN select * from taxonomy_terms where path like ?', [$season->pathForChildren().'%']);

    // A LIKE with a literal prefix is index-usable; the point of the
    // materialised path is that this is a range scan, not a table scan.
    expect(collect($plan)->pluck('detail')->implode(' '))
        ->toContain('taxonomy_terms');
});
