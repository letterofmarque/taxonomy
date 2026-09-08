<?php

declare(strict_types=1);

use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Exceptions\InvalidClassificationException;
use Marque\Taxonomy\Models\Facet;
use Marque\Taxonomy\Models\Term;
use Marque\Taxonomy\Services\Classifier;
use Marque\Taxonomy\Tests\TestUser;
use Marque\Trove\Models\Torrent;

/**
 * Classifying a torrent: putting it at a point in the hierarchy and hanging
 * facet values off it.
 */
beforeEach(function () {
    $this->nfl = ContentType::fromArray([
        'content_type' => 'nfl_game',
        'label' => 'NFL Game',
        'levels' => [
            ['season' => ['label' => 'Season', 'type' => 'year']],
            ['week' => ['label' => 'Week', 'type' => 'integer', 'range' => [1, 22]]],
        ],
        'facets' => ['resolution', 'subtitles'],
    ]);

    $this->classifier = app(Classifier::class);

    $this->torrent = Torrent::create([
        'info_hash' => str_repeat('a', 40),
        'name' => 'Eagles at Cowboys',
        'user_id' => TestUser::create([
            'name' => 'u', 'email' => 'u@example.com', 'password' => 'x',
        ])->id,
    ]);
});

describe('placing a torrent in the hierarchy', function () {
    it('creates the term path and attaches the torrent to its leaf', function () {
        $classification = $this->classifier->classify($this->torrent, $this->nfl, [
            'season' => '2006',
            'week' => '12',
        ]);

        expect($classification->content_type)->toBe('nfl_game')
            ->and($classification->term->value)->toBe('12')
            ->and($classification->term->level)->toBe('week')
            ->and($classification->term->parent->value)->toBe('2006');
    });

    it('reuses terms rather than duplicating them', function () {
        // Two torrents in the same week must share one week term, or the
        // hierarchy becomes a per-torrent list and every query breaks.
        $second = Torrent::create([
            'info_hash' => str_repeat('b', 40),
            'name' => 'Another game',
            'user_id' => $this->torrent->user_id,
        ]);

        $this->classifier->classify($this->torrent, $this->nfl, ['season' => '2006', 'week' => '12']);
        $this->classifier->classify($second, $this->nfl, ['season' => '2006', 'week' => '12']);

        expect(Term::count())->toBe(2);
    });

    it('builds the materialised path from the ancestors', function () {
        $classification = $this->classifier->classify($this->torrent, $this->nfl, [
            'season' => '2006',
            'week' => '12',
        ]);

        $season = $classification->term->parent;

        expect($season->path)->toBe('/')
            ->and($classification->term->path)->toBe('/'.$season->id.'/');
    });

    it('keeps a partial path when the definition allows stopping short', function () {
        // A torrent classified only to a season is legitimate — the whole
        // season's highlight reel, say. It just is not at the leaf.
        $classification = $this->classifier->classify($this->torrent, $this->nfl, ['season' => '2006']);

        expect($classification->term->level)->toBe('season')
            ->and($classification->term->parent)->toBeNull();
    });

    it('reclassifying moves the torrent rather than duplicating it', function () {
        // One classification per torrent per content type — the unique index
        // from CP2. Reclassifying is a correction, not a second opinion.
        $this->classifier->classify($this->torrent, $this->nfl, ['season' => '2006', 'week' => '12']);
        $moved = $this->classifier->classify($this->torrent, $this->nfl, ['season' => '2006', 'week' => '13']);

        expect($moved->term->value)->toBe('13')
            ->and($this->torrent->taxonomyClassifications()->count())->toBe(1);
    });
});

describe('the definition is enforced, not decorative', function () {
    it('refuses a value outside a declared range', function () {
        // week: { range: [1, 22] }. Week 47 is not a typo to store, it is a
        // mistake to refuse — otherwise the range in the definition is a
        // comment.
        expect(fn () => $this->classifier->classify($this->torrent, $this->nfl, [
            'season' => '2006',
            'week' => '47',
        ]))->toThrow(InvalidClassificationException::class);
    });

    it('refuses a value of the wrong type', function () {
        expect(fn () => $this->classifier->classify($this->torrent, $this->nfl, [
            'season' => 'last year',
        ]))->toThrow(InvalidClassificationException::class);
    });

    it('refuses a level the content type does not declare', function () {
        expect(fn () => $this->classifier->classify($this->torrent, $this->nfl, [
            'season' => '2006',
            'innings' => '2',
        ]))->toThrow(InvalidClassificationException::class);
    });

    it('refuses a gap in the path', function () {
        // Week without season is an orphan by construction: there is no
        // "week 12" independent of which season's week 12 it is. That is the
        // inconsistent-depth failure the XenForo dump is full of.
        expect(fn () => $this->classifier->classify($this->torrent, $this->nfl, [
            'week' => '12',
        ]))->toThrow(InvalidClassificationException::class);
    });

    it('refuses an empty classification', function () {
        expect(fn () => $this->classifier->classify($this->torrent, $this->nfl, []))
            ->toThrow(InvalidClassificationException::class);
    });
});

describe('facet values', function () {
    it('attaches many values of one facet to a torrent', function () {
        // Acceptance criterion 9, and the reason assignment is many-to-many.
        $this->classifier->classify($this->torrent, $this->nfl, ['season' => '2006'], [
            'subtitles' => ['en', 'es', 'fr', 'de', 'ja', 'ko', 'pt', 'it', 'nl', 'pl', 'sv', 'da'],
            'resolution' => ['1080p'],
        ]);

        expect($this->torrent->taxonomyFacetValues()->count())->toBe(13);
    });

    it('creates facet vocabularies on demand', function () {
        // A facet named in a definition should not also require a seeding
        // step before anything can use it.
        $this->classifier->classify($this->torrent, $this->nfl, ['season' => '2006'], [
            'resolution' => ['1080p'],
        ]);

        expect(Facet::where('name', 'resolution')->exists())->toBeTrue();
    });

    it('refuses a facet the content type does not declare', function () {
        expect(fn () => $this->classifier->classify($this->torrent, $this->nfl, ['season' => '2006'], [
            'broadcaster' => ['sky'],
        ]))->toThrow(InvalidClassificationException::class);
    });

    it('replaces facet values on reclassification rather than accumulating', function () {
        $this->classifier->classify($this->torrent, $this->nfl, ['season' => '2006'], [
            'subtitles' => ['en', 'es'],
        ]);

        $this->classifier->classify($this->torrent, $this->nfl, ['season' => '2006'], [
            'subtitles' => ['fr'],
        ]);

        expect($this->torrent->taxonomyFacetValues()->pluck('value')->all())->toBe(['fr']);
    });
});

describe('the grouping key', function () {
    it('is written and read back without core interpreting it', function () {
        // Core groups; it does not model what a "thing" is. Any string an
        // domain package invents is acceptable.
        $classification = $this->classifier->classify(
            $this->torrent,
            $this->nfl,
            ['season' => '2006', 'week' => '12'],
            [],
            'nfl:2006:12:phi-dal',
        );

        expect($classification->grouping_key)->toBe('nfl:2006:12:phi-dal');
    });

    it('finds the other encodes of the same thing', function () {
        $keys = [];

        foreach (['b', 'c', 'd'] as $char) {
            $other = Torrent::create([
                'info_hash' => str_repeat($char, 40),
                'name' => 'encode '.$char,
                'user_id' => $this->torrent->user_id,
            ]);

            $keys[] = $this->classifier->classify(
                $other, $this->nfl, ['season' => '2006', 'week' => '12'], [], 'nfl:2006:12:phi-dal',
            );
        }

        $this->classifier->classify(
            $this->torrent, $this->nfl, ['season' => '2006', 'week' => '12'], [], 'nfl:2006:12:phi-dal',
        );

        expect($this->classifier->siblings($this->torrent)->count())->toBe(3);
    });

    it('has no siblings when the key is null', function () {
        // A content type that finds grouping meaningless leaves it null and
        // nothing degrades.
        $this->classifier->classify($this->torrent, $this->nfl, ['season' => '2006']);

        expect($this->classifier->siblings($this->torrent)->count())->toBe(0);
    });
});
