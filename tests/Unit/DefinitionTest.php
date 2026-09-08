<?php

declare(strict_types=1);

use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Definitions\Facet;
use Marque\Taxonomy\Definitions\Level;

/**
 * The definition object. Ordinary PHP, fully testable — the Spec chose YAML
 * over PHP classes for authoring, but what the YAML produces is still a plain
 * object with no framework coupling.
 */
describe('a content type', function () {
    it('carries its levels in declaration order', function () {
        // Order is the hierarchy: season contains week, not the reverse. A
        // hash map would lose it, so this is a list.
        $type = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 1,
            'levels' => [
                ['season' => ['label' => 'Season', 'type' => 'year']],
                ['week' => ['label' => 'Week', 'type' => 'integer', 'range' => [1, 22]]],
            ],
            'facets' => ['resolution', 'source'],
        ]);

        expect($type->name)->toBe('nfl_game')
            ->and($type->label)->toBe('NFL Game')
            ->and($type->version)->toBe(1)
            ->and($type->levelNames())->toBe(['season', 'week']);
    });

    it('exposes a level by name and knows its depth', function () {
        $type = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [
                ['season' => ['label' => 'Season', 'type' => 'year']],
                ['week' => ['label' => 'Week', 'type' => 'integer', 'range' => [1, 22]]],
            ],
        ]);

        expect($type->level('week'))->toBeInstanceOf(Level::class)
            ->and($type->level('week')->depth)->toBe(1)
            ->and($type->level('season')->depth)->toBe(0)
            ->and($type->level('nope'))->toBeNull();
    });

    it('defaults version to 1 when the file does not say', function () {
        // Every definition has a version whether the author wrote one or not —
        // CP5's upgrade machinery compares against it, and a null would make
        // "has this changed?" unanswerable.
        $type = ContentType::fromArray([
            'content_type' => 'simple',
            'label' => 'Simple',
            'levels' => [['category' => ['label' => 'Category', 'type' => 'string']]],
        ]);

        expect($type->version)->toBe(1);
    });

    it('holds facet references rather than facet definitions', function () {
        // Facets are shared vocabularies owned by the tracker, not by a
        // content type. A definition names them; it does not define them.
        $type = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [['season' => ['label' => 'Season', 'type' => 'year']]],
            'facets' => ['resolution', 'subtitles'],
        ]);

        expect($type->facets)->toBe(['resolution', 'subtitles']);
    });

    it('works with no facets at all', function () {
        $type = ContentType::fromArray([
            'content_type' => 'bare',
            'label' => 'Bare',
            'levels' => [['category' => ['label' => 'Category', 'type' => 'string']]],
        ]);

        expect($type->facets)->toBe([]);
    });
});

describe('a level', function () {
    it('keeps its declared range', function () {
        $level = Level::fromArray('week', ['label' => 'Week', 'type' => 'integer', 'range' => [1, 22]], 0);

        expect($level->name)->toBe('week')
            ->and($level->type)->toBe('integer')
            ->and($level->min)->toBe(1)
            ->and($level->max)->toBe(22);
    });

    it('has no range when none is declared', function () {
        $level = Level::fromArray('season', ['label' => 'Season', 'type' => 'year'], 0);

        expect($level->min)->toBeNull()
            ->and($level->max)->toBeNull();
    });

    it('falls back to the name as a label', function () {
        // A missing label is a cosmetic omission, not a broken definition.
        $level = Level::fromArray('season', ['type' => 'year'], 0);

        expect($level->label)->toBe('season');
    });

    it('accepts a value inside its range and rejects one outside', function () {
        $week = Level::fromArray('week', ['type' => 'integer', 'range' => [1, 22]], 0);

        expect($week->accepts('12'))->toBeTrue()
            ->and($week->accepts('1'))->toBeTrue()
            ->and($week->accepts('22'))->toBeTrue()
            ->and($week->accepts('0'))->toBeFalse()
            ->and($week->accepts('23'))->toBeFalse()
            ->and($week->accepts('banana'))->toBeFalse();
    });

    it('validates a year like a year', function () {
        $season = Level::fromArray('season', ['type' => 'year'], 0);

        expect($season->accepts('2006'))->toBeTrue()
            ->and($season->accepts('12'))->toBeFalse()
            ->and($season->accepts('not a year'))->toBeFalse();
    });

    it('accepts anything non-empty for a string level', function () {
        $category = Level::fromArray('category', ['type' => 'string'], 0);

        expect($category->accepts('Drama'))->toBeTrue()
            ->and($category->accepts(''))->toBeFalse();
    });
});

describe('a facet', function () {
    it('is single-valued only when the definition says so', function () {
        // Cardinality is a definition-declared constraint, not a schema one
        // (resolved open question 1). Many-valued is the default, because a
        // Netflix rip carries a dozen subtitle tracks.
        $subtitles = new Facet('subtitles', 'Subtitles', false);
        $resolution = new Facet('resolution', 'Resolution', true);

        expect($subtitles->single)->toBeFalse()
            ->and($resolution->single)->toBeTrue();
    });
});
