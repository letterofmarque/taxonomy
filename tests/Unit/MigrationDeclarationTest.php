<?php

declare(strict_types=1);

use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Definitions\Migration;
use Marque\Taxonomy\Definitions\Validator;

/**
 * A definition declares how to get from its previous shape to this one.
 *
 * The forcing function, from Dan: *"should a migration path/process be
 * mandated for that, the structure SHOULD be known?"* Yes — the package author
 * is the only person who knows both the old shape and the new one. Making the
 * admin infer what the author's change means is backwards.
 */
describe('parsing a migration declaration', function () {
    it('reads the steps for one version bump', function () {
        $type = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 2,
            'levels' => [
                ['season' => ['type' => 'year']],
                ['conference' => ['type' => 'string']],
                ['week' => ['type' => 'integer', 'range' => [1, 22]]],
            ],
            'migrations' => [
                ['from' => 1, 'add_level' => ['conference' => ['after' => 'season', 'default' => null]]],
            ],
        ]);

        expect($type->migrations)->toHaveCount(1)
            ->and($type->migrations[0])->toBeInstanceOf(Migration::class)
            ->and($type->migrations[0]->from)->toBe(1)
            ->and($type->migrations[0]->to())->toBe(2);
    });

    it('finds the path from an installed version to the shipped one', function () {
        // A tracker two versions behind needs both steps, in order.
        $type = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 3,
            'levels' => [['season' => ['type' => 'year']]],
            'migrations' => [
                ['from' => 1, 'rename_level' => ['from' => 'yr', 'to' => 'year']],
                ['from' => 2, 'rename_level' => ['from' => 'year', 'to' => 'season']],
            ],
        ]);

        expect($type->migrationPathFrom(1))->toHaveCount(2)
            ->and($type->migrationPathFrom(2))->toHaveCount(1)
            ->and($type->migrationPathFrom(3))->toBe([]);
    });

    it('returns null when the chain is broken', function () {
        // v1 to v3 declared, but nothing gets from 1 to 2. An admin on v1
        // cannot be upgraded, and pretending otherwise would silently skip a
        // step.
        $type = ContentType::fromArray([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 3,
            'levels' => [['season' => ['type' => 'year']]],
            'migrations' => [
                ['from' => 2, 'rename_level' => ['from' => 'year', 'to' => 'season']],
            ],
        ]);

        expect($type->migrationPathFrom(1))->toBeNull();
    });

    it('carries each step kind', function () {
        $type = ContentType::fromArray([
            'content_type' => 'x',
            'label' => 'X',
            'version' => 2,
            'levels' => [['a' => ['type' => 'string']]],
            'migrations' => [[
                'from' => 1,
                'add_level' => ['b' => ['after' => 'a', 'default' => 'unknown']],
                'rename_level' => ['from' => 'old', 'to' => 'a'],
                'remove_level' => ['c'],
                'add_facet' => ['resolution'],
                'remove_facet' => ['broadcaster'],
            ]],
        ]);

        $migration = $type->migrations[0];

        expect($migration->addedLevels)->toHaveKey('b')
            ->and($migration->renamedLevels)->toBe([['from' => 'old', 'to' => 'a']])
            ->and($migration->removedLevels)->toBe(['c'])
            ->and($migration->addedFacets)->toBe(['resolution'])
            ->and($migration->removedFacets)->toBe(['broadcaster']);
    });

    it('knows which steps destroy data and which do not', function () {
        // Ceremony scales with damage. Adding a nullable level is a one-line
        // declaration; removing one makes the author say what happens.
        $additive = new Migration(from: 1, addedLevels: ['b' => ['default' => null]], addedFacets: ['res']);
        $destructive = new Migration(from: 1, removedLevels: ['week']);
        $rename = new Migration(from: 1, renamedLevels: [['from' => 'week', 'to' => 'round']]);

        expect($additive->isDestructive())->toBeFalse()
            ->and($destructive->isDestructive())->toBeTrue()
            // A rename preserves every classification — that is the entire
            // point of declaring it rather than letting the loader see a level
            // vanish and another appear.
            ->and($rename->isDestructive())->toBeFalse();
    });
});

describe('a version bump without a migration path is refused', function () {
    it('rejects a definition that bumps version and declares no migrations', function () {
        // "A version bump with no migration path is refused, not warned
        // about." The refusal is the forcing function.
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 2,
            'levels' => [['season' => ['type' => 'year']]],
        ]);

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('migration');
    });

    it('accepts version 1 with no migrations, since there is nothing to come from', function () {
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 1,
            'levels' => [['season' => ['type' => 'year']]],
        ]);

        expect($errors)->toBe([]);
    });

    it('rejects a migration whose from is not below the declared version', function () {
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 2,
            'levels' => [['season' => ['type' => 'year']]],
            'migrations' => [['from' => 5, 'add_facet' => ['x']]],
        ]);

        expect($errors)->not->toBeEmpty();
    });

    it('rejects a migration chain with a hole in it', function () {
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 3,
            'levels' => [['season' => ['type' => 'year']]],
            'migrations' => [['from' => 2, 'add_facet' => ['x']]],
        ]);

        expect($errors)->not->toBeEmpty()
            ->and(implode(' ', $errors))->toContain('1');
    });

    it('rejects a migration step that declares nothing', function () {
        $errors = (new Validator)->validate([
            'content_type' => 'nfl_game',
            'label' => 'NFL Game',
            'version' => 2,
            'levels' => [['season' => ['type' => 'year']]],
            'migrations' => [['from' => 1]],
        ]);

        expect($errors)->not->toBeEmpty();
    });
});
