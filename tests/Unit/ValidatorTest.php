<?php

declare(strict_types=1);

use Marque\Taxonomy\Definitions\Validator;

/**
 * Strict, and strictness is v1 — resolved open question 2. Dan: "as it's being
 * used as code it would need to be fairly stringent."
 *
 * The error MESSAGES matter as much as the detection: an admin who cannot tell
 * which file broke and why is exactly the failure mode the Spec's
 * fail-completely rule exists to prevent. What is deferrable is the ergonomic
 * polish ("did you mean 'resolution'?"), not the diagnosis.
 */
describe('a well-formed definition', function () {
    it('passes with no errors', function () {
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 1,
            'levels' => [
                ['season' => ['label' => 'Season', 'type' => 'year']],
                ['week' => ['label' => 'Week', 'type' => 'integer', 'range' => [1, 22]]],
            ],
            'facets' => ['resolution'],
        ]);

        expect($errors)->toBe([]);
    });
});

describe('structural requirements', function () {
    it('rejects a definition with no content_type', function () {
        $errors = (new Validator)->validate([
            'label' => 'Nameless',
            'levels' => [['a' => ['type' => 'string']]],
        ]);

        expect($errors)->toHaveCount(1)
            ->and($errors[0])->toContain('content_type');
    });

    it('rejects a content_type name that is not a usable identifier', function () {
        // The name is used as a scoping key in the database and on the upload
        // form. Spaces and punctuation there are a problem waiting to happen.
        $errors = (new Validator)->validate([
            'content_type' => 'NFL Game!',
            'label' => 'NFL Game',
            'levels' => [['a' => ['type' => 'string']]],
        ]);

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('content_type');
    });

    it('rejects a definition with no levels', function () {
        // A content type with no hierarchy classifies nothing.
        $errors = (new Validator)->validate([
            'content_type' => 'empty',
            'label' => 'Empty',
            'levels' => [],
        ]);

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('level');
    });

    it('rejects a version that is not a positive integer', function () {
        // CP5's upgrade machinery compares versions. "2.0" or "draft" makes
        // that comparison meaningless.
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => '2.0',
            'levels' => [['season' => ['type' => 'year']]],
        ]);

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('version');
    });
});

describe('duplicate names within a content type', function () {
    it('rejects two levels with the same name', function () {
        // Explicitly named in the checkpoint: "no duplicate names within a
        // content type".
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [
                ['week' => ['type' => 'integer']],
                ['week' => ['type' => 'string']],
            ],
        ]);

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('week');
    });

    it('rejects the same facet listed twice', function () {
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [['season' => ['type' => 'year']]],
            'facets' => ['resolution', 'resolution'],
        ]);

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('resolution');
    });

    it('allows a level and a facet to share a name across the boundary', function () {
        // They live in different namespaces — a level is scoped to this type,
        // a facet is a shared vocabulary. Rejecting this would be strictness
        // for its own sake.
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [['source' => ['type' => 'string']]],
            'facets' => ['source'],
        ]);

        expect($errors)->toBe([]);
    });
});

describe('level declarations', function () {
    it('rejects an unknown level type', function () {
        // "Declared types and ranges are well-formed."
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [['season' => ['type' => 'yearr']]],
        ]);

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('yearr');
    });

    it('rejects a range that is not two numbers', function () {
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [['week' => ['type' => 'integer', 'range' => [1]]]],
        ]);

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('range');
    });

    it('rejects a backwards range', function () {
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [['week' => ['type' => 'integer', 'range' => [22, 1]]]],
        ]);

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('range');
    });

    it('rejects a range on a level type that cannot have one', function () {
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [['name' => ['type' => 'string', 'range' => [1, 10]]]],
        ]);

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('range');
    });

    it('rejects a level name that is not a usable identifier', function () {
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [['the week!' => ['type' => 'integer']]],
        ]);

        expect($errors)->not->toBeEmpty();
    });
});

describe('reporting', function () {
    it('reports every problem at once rather than stopping at the first', function () {
        // An admin fixing one error per run is an admin running the validator
        // five times. Collect them all.
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'levels' => [
                ['week' => ['type' => 'nonsense']],
                ['week' => ['type' => 'integer', 'range' => [9, 2]]],
            ],
            'facets' => ['resolution', 'resolution'],
        ]);

        // Unknown type, duplicate level, backwards range, duplicate facet.
        expect(count($errors))->toBeGreaterThanOrEqual(4);
    });
});
