<?php

declare(strict_types=1);

use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Models\Classification;
use Marque\Taxonomy\Models\Facet;
use Marque\Taxonomy\Services\Classifier;
use Marque\Taxonomy\Services\DriftReport;
use Marque\Taxonomy\Services\Upgrader;
use Marque\Taxonomy\Tests\TestUser;
use Marque\Trove\Models\Torrent;

/**
 * The admin changed their OWN definition — no version bump, no package author
 * involved. Dan's framing governs this:
 *
 * > "this isn't an easy on them hard on us thing, this is protecting some
 * > people from themselves, we need to make it harder for them to fuck things
 * > up like that."
 *
 * Additive changes apply on load with no ceremony. Destructive ones are
 * detected, reported with counts, and require an explicit command.
 */
beforeEach(function () {
    $this->live = ContentType::fromArray([
        'content_type' => 'nfl_game',
        'label' => 'NFL Game',
        'levels' => [
            ['season' => ['type' => 'year']],
            ['week' => ['type' => 'integer', 'range' => [1, 22]]],
        ],
        'facets' => ['resolution', 'subtitles'],
    ]);

    $classifier = app(Classifier::class);
    $user = TestUser::create(['name' => 'u', 'email' => 'u@example.com', 'password' => 'x']);

    foreach (['12', '13', '14'] as $i => $week) {
        $torrent = Torrent::create([
            'info_hash' => str_pad((string) ($i + 1), 40, '0', STR_PAD_LEFT),
            'name' => 'Game '.$week,
            'user_id' => $user->id,
        ]);

        $classifier->classify($torrent, $this->live, ['season' => '2006', 'week' => $week], [
            'resolution' => ['1080p'],
            'subtitles' => ['en'],
        ]);
    }

    $this->report = app(DriftReport::class);
});

describe('additive changes need no ceremony', function () {
    it('sees nothing destructive in a new facet', function () {
        $edited = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [
                ['season' => ['type' => 'year']],
                ['week' => ['type' => 'integer', 'range' => [1, 22]]],
            ],
            'facets' => ['resolution', 'subtitles', 'broadcaster'],
        ]);

        expect($this->report->for($edited)->isDestructive())->toBeFalse();
    });

    it('sees nothing destructive in a new level appended below the leaf', function () {
        // Deeper classification becomes possible; nothing already classified
        // stops being valid.
        $edited = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [
                ['season' => ['type' => 'year']],
                ['week' => ['type' => 'integer', 'range' => [1, 22]]],
                ['quarter' => ['type' => 'integer', 'range' => [1, 4]]],
            ],
            'facets' => ['resolution', 'subtitles'],
        ]);

        expect($this->report->for($edited)->isDestructive())->toBeFalse();
    });
});

describe('destructive changes are detected and counted', function () {
    it('reports how many terms and torrents a removed level would orphan', function () {
        // The count is the point. "This will orphan 3 torrents" is a decision
        // an admin can make; "are you sure?" is not.
        $edited = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [['season' => ['type' => 'year']]],
            'facets' => ['resolution', 'subtitles'],
        ]);

        $report = $this->report->for($edited);

        expect($report->isDestructive())->toBeTrue()
            ->and($report->removedLevels)->toBe(['week'])
            ->and($report->orphanedTerms)->toBe(3)
            ->and($report->orphanedTorrents)->toBe(3);
    });

    it('reports a dropped facet and what it would unassign', function () {
        $edited = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [
                ['season' => ['type' => 'year']],
                ['week' => ['type' => 'integer', 'range' => [1, 22]]],
            ],
            'facets' => ['resolution'],
        ]);

        $report = $this->report->for($edited);

        expect($report->isDestructive())->toBeTrue()
            ->and($report->removedFacets)->toBe(['subtitles'])
            ->and($report->unassignedValues)->toBe(3);
    });

    it('treats a renamed content type as destructive', function () {
        // From the data's point of view the old type simply vanished. Every
        // classification under it orphans.
        $edited = ContentType::fromArray([
            'content_type' => 'nfl_fixture',
            'label' => 'NFL Fixture',
            'levels' => [
                ['season' => ['type' => 'year']],
                ['week' => ['type' => 'integer', 'range' => [1, 22]]],
            ],
        ]);

        $report = $this->report->forRemovedContentType('nfl_game');

        expect($report->isDestructive())->toBeTrue()
            ->and($report->orphanedTorrents)->toBe(3);
    });

    it('counts nothing when the level exists but holds no data', function () {
        // Removing an unused level is technically destructive and practically
        // free. The count says so, and the admin can decide accordingly.
        $unused = ContentType::fromArray([
            'content_type' => 'other_type',
            'label' => 'Other',
            'levels' => [['a' => ['type' => 'string']], ['b' => ['type' => 'string']]],
        ]);

        $edited = ContentType::fromArray([
            'content_type' => 'other_type',
            'label' => 'Other',
            'levels' => [['a' => ['type' => 'string']]],
        ]);

        $report = $this->report->for($edited);

        expect($report->orphanedTorrents)->toBe(0);
    });
});

describe('data is never deleted — proven, not asserted', function () {
    it('leaves every torrent and classification row after the worst case', function () {
        // Remove every level and every facet at once: the most destructive
        // edit expressible. Nothing may be deleted.
        $torrentsBefore = Torrent::count();
        $classificationsBefore = Classification::count();

        $gutted = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [['season' => ['type' => 'year']]],
            'facets' => [],
        ]);

        app(Upgrader::class)->applyDestructive($gutted, $this->report->for($gutted));

        expect(Torrent::count())->toBe($torrentsBefore)
            ->and(Classification::count())->toBe($classificationsBefore)
            ->and(Classification::whereNull('term_id')->count())->toBe(3);
    });

    it('keeps the content type on an orphaned row so it says what it was', function () {
        // Nulling term_id must not also lose which taxonomy the row belonged
        // to, or the orphan is unrecoverable in practice even though the row
        // survives.
        $gutted = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [['season' => ['type' => 'year']]],
        ]);

        app(Upgrader::class)->applyDestructive($gutted, $this->report->for($gutted));

        expect(Classification::whereNull('term_id')->pluck('content_type')->unique()->all())
            ->toBe(['nfl_game']);
    });

    it('does not touch the facet vocabulary itself when a type stops using it', function () {
        // Other content types may share `subtitles`. Dropping it from one
        // definition must not delete the vocabulary out from under the others.
        $edited = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [
                ['season' => ['type' => 'year']],
                ['week' => ['type' => 'integer', 'range' => [1, 22]]],
            ],
            'facets' => ['resolution'],
        ]);

        app(Upgrader::class)->applyDestructive($edited, $this->report->for($edited));

        expect(Facet::where('name', 'subtitles')->exists())->toBeTrue();
    });
});
