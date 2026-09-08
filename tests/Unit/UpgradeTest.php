<?php

declare(strict_types=1);

use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Exceptions\UpgradeRefusedException;
use Marque\Taxonomy\Models\Classification;
use Marque\Taxonomy\Models\InstalledVersion;
use Marque\Taxonomy\Models\Term;
use Marque\Taxonomy\Services\Classifier;
use Marque\Taxonomy\Services\Upgrader;
use Marque\Taxonomy\Tests\TestUser;
use Marque\Trove\Models\Torrent;

/**
 * A package author changed the definition underneath a running tracker.
 *
 * `composer update` must never reshape a live catalogue. The whole mechanism
 * exists so the admin makes that decision knowingly, with a count of what is
 * affected in front of them.
 */
beforeEach(function () {
    $this->v1 = ContentType::fromArray([
        'content_type' => 'nfl_game',
        'label' => 'NFL Game',
        'version' => 1,
        'levels' => [
            ['season' => ['type' => 'year']],
            ['week' => ['type' => 'integer', 'range' => [1, 22]]],
        ],
        'facets' => ['resolution'],
    ]);

    $this->classifier = app(Classifier::class);
    $this->upgrader = app(Upgrader::class);

    $this->user = TestUser::create(['name' => 'u', 'email' => 'u@example.com', 'password' => 'x']);

    $this->classifyOne = function (string $week = '12'): Torrent {
        static $n = 0;
        $torrent = Torrent::create([
            'info_hash' => str_pad((string) ++$n, 40, '0', STR_PAD_LEFT),
            'name' => 'Game '.$n,
            'user_id' => $this->user->id,
        ]);

        $this->classifier->classify($torrent, $this->v1, ['season' => '2006', 'week' => $week]);

        return $torrent;
    };
});

describe('tracking what is installed', function () {
    it('records the version a content type was first used at', function () {
        // Without this there is nothing to compare a shipped version against,
        // and "has this changed underneath me?" is unanswerable.
        ($this->classifyOne)();

        expect(InstalledVersion::for('nfl_game')?->version)->toBe(1);
    });

    it('does not bump the installed version just because a new file appeared', function () {
        ($this->classifyOne)();

        $v2 = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 2,
            'levels' => [['season' => ['type' => 'year']], ['week' => ['type' => 'integer']]],
            'migrations' => [['from' => 1, 'add_facet' => ['source']]],
        ]);

        // Merely seeing v2 must not move the tracker onto it.
        $this->upgrader->pending([$v2]);

        expect(InstalledVersion::for('nfl_game')?->version)->toBe(1);
    });
});

describe('detecting a pending upgrade', function () {
    it('reports the type, both versions, and how many torrents are affected', function () {
        // The message an admin needs in order to decide. A bare "an update is
        // available" tells them nothing about the risk.
        ($this->classifyOne)('12');
        ($this->classifyOne)('13');
        ($this->classifyOne)('14');

        $v2 = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 2,
            'levels' => [['season' => ['type' => 'year']], ['week' => ['type' => 'integer']]],
            'migrations' => [['from' => 1, 'add_facet' => ['source']]],
        ]);

        $pending = $this->upgrader->pending([$v2]);

        expect($pending)->toHaveCount(1)
            ->and($pending[0]['content_type'])->toBe('nfl_game')
            ->and($pending[0]['from'])->toBe(1)
            ->and($pending[0]['to'])->toBe(2)
            ->and($pending[0]['affected'])->toBe(3);
    });

    it('reports nothing when the shipped version matches', function () {
        ($this->classifyOne)();

        expect($this->upgrader->pending([$this->v1]))->toBe([]);
    });

    it('reports nothing for a content type nothing is classified under', function () {
        // Never used means nothing to protect. Adopting v2 straight away is
        // correct here, not a risk.
        $v2 = ContentType::fromArray([
            'content_type' => 'unused',
            'label' => 'Unused',
            'version' => 2,
            'levels' => [['a' => ['type' => 'string']]],
            'migrations' => [['from' => 1, 'add_facet' => ['x']]],
        ]);

        expect($this->upgrader->pending([$v2]))->toBe([]);
    });
});

describe('applying an upgrade', function () {
    it('preserves every classification through a level rename', function () {
        // Acceptance criterion 10, and the case that justifies the whole
        // mechanism. To a naive loader `week` vanishes and `round` appears,
        // orphaning every classification. Declared, they all survive.
        ($this->classifyOne)('12');
        ($this->classifyOne)('13');

        $before = Classification::whereNotNull('term_id')->count();

        $v2 = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 2,
            'levels' => [
                ['season' => ['type' => 'year']],
                ['round' => ['type' => 'integer', 'range' => [1, 22]]],
            ],
            'migrations' => [['from' => 1, 'rename_level' => ['from' => 'week', 'to' => 'round']]],
        ]);

        $this->upgrader->apply($v2);

        expect(Classification::whereNotNull('term_id')->count())->toBe($before)
            ->and(Term::where('level', 'round')->count())->toBe(2)
            ->and(Term::where('level', 'week')->count())->toBe(0)
            ->and(InstalledVersion::for('nfl_game')?->version)->toBe(2);
    });

    it('adds a level without disturbing what is already classified', function () {
        ($this->classifyOne)();

        $v2 = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 2,
            'levels' => [
                ['season' => ['type' => 'year']],
                ['conference' => ['type' => 'string']],
                ['week' => ['type' => 'integer', 'range' => [1, 22]]],
            ],
            'migrations' => [['from' => 1, 'add_level' => ['conference' => ['after' => 'season', 'default' => null]]]],
        ]);

        $this->upgrader->apply($v2);

        expect(Classification::whereNotNull('term_id')->count())->toBe(1);
    });

    it('orphans rather than deletes when a level is removed', function () {
        // The property the whole Spec turns on: "data is never deleted by a
        // definition edit — it becomes unreferenced and recoverable."
        $torrent = ($this->classifyOne)();

        $v2 = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 2,
            'levels' => [['season' => ['type' => 'year']]],
            'migrations' => [['from' => 1, 'remove_level' => ['week']]],
        ]);

        $this->upgrader->apply($v2);

        // The torrent still exists, and so does its classification row.
        expect(Torrent::find($torrent->id))->not->toBeNull()
            ->and(Classification::where('torrent_id', $torrent->id)->count())->toBe(1)
            // The week terms are gone, so the classification is orphaned —
            // recoverable, not destroyed.
            ->and(Classification::where('torrent_id', $torrent->id)->first()->term_id)->toBeNull();
    });

    it('refuses to apply when no migration path exists', function () {
        ($this->classifyOne)();

        $v2 = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 2,
            'levels' => [['season' => ['type' => 'year']]],
        ]);

        expect(fn () => $this->upgrader->apply($v2))
            ->toThrow(UpgradeRefusedException::class);
    });

    it('walks several versions in order', function () {
        ($this->classifyOne)();

        $v3 = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 3,
            'levels' => [
                ['season' => ['type' => 'year']],
                ['round' => ['type' => 'integer', 'range' => [1, 22]]],
            ],
            'migrations' => [
                ['from' => 1, 'rename_level' => ['from' => 'week', 'to' => 'stage']],
                ['from' => 2, 'rename_level' => ['from' => 'stage', 'to' => 'round']],
            ],
        ]);

        $this->upgrader->apply($v3);

        expect(Term::where('level', 'round')->count())->toBe(1)
            ->and(Term::where('level', 'stage')->count())->toBe(0)
            ->and(InstalledVersion::for('nfl_game')?->version)->toBe(3);
    });

    it('leaves everything untouched when a step fails partway', function () {
        // Atomicity matters more here than almost anywhere: a half-applied
        // upgrade is precisely the undiagnosable state the design exists to
        // prevent.
        ($this->classifyOne)();

        $broken = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 2,
            'levels' => [['season' => ['type' => 'year']]],
            'migrations' => [[
                'from' => 1,
                'rename_level' => ['from' => 'week', 'to' => 'round'],
                // Removing a level that does not exist is a declaration error,
                // discovered mid-apply.
                'remove_level' => ['nonexistent'],
            ]],
        ]);

        try {
            $this->upgrader->apply($broken);
        } catch (Throwable) {
            // expected
        }

        expect(Term::where('level', 'week')->count())->toBe(1)
            ->and(Term::where('level', 'round')->count())->toBe(0)
            ->and(InstalledVersion::for('nfl_game')?->version)->toBe(1);
    });
});
