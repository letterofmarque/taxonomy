<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Definitions;

/**
 * Strict validation of a raw definition array, before it ever becomes a
 * ContentType.
 *
 * Strictness is v1, resolved with Dan: *"as it's being used as code it would
 * need to be fairly stringent."* A definition is executable configuration —
 * it drives the upload form, the validation and the query builder — so a typo
 * that loads is a typo that breaks a catalogue quietly, which is worse than a
 * refusal.
 *
 * Every problem is collected rather than stopping at the first. An admin
 * fixing one error per run is an admin running the validator five times.
 *
 * Deferred deliberately (ergonomics, not load-bearing): "did you mean
 * 'resolution'?" suggestions and unused-vocabulary warnings.
 */
final class Validator
{
    /**
     * An identifier used as a database scoping key and a form field name.
     * Lowercase, digits and underscores — nothing that needs quoting or
     * escaping downstream.
     */
    private const IDENTIFIER = '/^[a-z][a-z0-9_]*$/';

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string> Every problem found; empty means valid.
     */
    public function validate(array $definition): array
    {
        $errors = [];

        $this->validateName($definition, $errors);
        $this->validateVersion($definition, $errors);
        $this->validateLevels($definition, $errors);
        $this->validateFacets($definition, $errors);
        $this->validateMigrations($definition, $errors);

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $errors
     */
    private function validateName(array $definition, array &$errors): void
    {
        $name = $definition['content_type'] ?? null;

        if (! is_string($name) || $name === '') {
            $errors[] = 'Missing required key: content_type.';

            return;
        }

        if (preg_match(self::IDENTIFIER, $name) !== 1) {
            $errors[] = sprintf(
                'Invalid content_type "%s": must start with a letter and contain only lowercase letters, digits and underscores.',
                $name,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $errors
     */
    private function validateVersion(array $definition, array &$errors): void
    {
        if (! array_key_exists('version', $definition)) {
            return;
        }

        $version = $definition['version'];

        // Deliberately int-only: CP5's upgrade machinery compares versions to
        // decide whether a live catalogue needs reshaping, and "2.0" or
        // "draft" makes that comparison meaningless.
        if (! is_int($version) || $version < 1) {
            $errors[] = sprintf(
                'Invalid version "%s": must be a positive integer.',
                is_scalar($version) ? (string) $version : gettype($version),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $errors
     */
    private function validateLevels(array $definition, array &$errors): void
    {
        $levels = $definition['levels'] ?? null;

        if (! is_array($levels) || $levels === []) {
            $errors[] = 'A content type must declare at least one level.';

            return;
        }

        $seen = [];

        foreach ($levels as $entry) {
            if (! is_array($entry)) {
                // The example is deliberately abstract. A concrete one ("- season:
                // ...") reads better, but core is not supposed to know what a
                // season is — and an example in help text is exactly how that
                // knowledge starts leaking in. MultiDomainTest greps for it.
                $errors[] = 'Each level must be a single-key map, e.g. "- <name>: { type: string }".';

                continue;
            }

            foreach ($entry as $name => $declaration) {
                $name = (string) $name;

                if (preg_match(self::IDENTIFIER, $name) !== 1) {
                    $errors[] = sprintf(
                        'Invalid level name "%s": must start with a letter and contain only lowercase letters, digits and underscores.',
                        $name,
                    );
                }

                if (isset($seen[$name])) {
                    $errors[] = sprintf('Duplicate level "%s" in this content type.', $name);
                }

                $seen[$name] = true;

                $this->validateLevelDeclaration($name, is_array($declaration) ? $declaration : [], $errors);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $declaration
     * @param  list<string>  $errors
     */
    private function validateLevelDeclaration(string $name, array $declaration, array &$errors): void
    {
        $type = $declaration['type'] ?? 'string';

        if (! is_string($type) || ! in_array($type, Level::TYPES, true)) {
            $errors[] = sprintf(
                'Unknown type "%s" on level "%s": expected one of %s.',
                is_scalar($type) ? (string) $type : gettype($type),
                $name,
                implode(', ', Level::TYPES),
            );

            // The range check below reads the type, so stop here rather than
            // reporting a second, confusing error about a level whose type is
            // already known to be wrong.
            return;
        }

        if (! array_key_exists('range', $declaration)) {
            return;
        }

        $range = $declaration['range'];

        if (! in_array($type, Level::RANGEABLE, true)) {
            $errors[] = sprintf(
                'Level "%s" declares a range, which is not meaningful for type "%s".',
                $name,
                $type,
            );

            return;
        }

        if (! is_array($range) || count($range) !== 2 || ! is_numeric($range[0] ?? null) || ! is_numeric($range[1] ?? null)) {
            $errors[] = sprintf('Invalid range on level "%s": expected two numbers, e.g. [1, 22].', $name);

            return;
        }

        if ((int) $range[0] > (int) $range[1]) {
            $errors[] = sprintf('Invalid range on level "%s": %s is greater than %s.', $name, (string) $range[0], (string) $range[1]);
        }
    }

    /**
     * A version bump with no migration path is refused, not warned about.
     *
     * That refusal is the forcing function. The package author is the only
     * person who knows both the old shape and the new one, so the work belongs
     * with them rather than with the admin who would otherwise have to guess
     * what the change meant for their catalogue.
     *
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $errors
     */
    private function validateMigrations(array $definition, array &$errors): void
    {
        $version = $definition['version'] ?? 1;

        if (! is_int($version) || $version < 1) {
            // Already reported by validateVersion; nothing coherent to check.
            return;
        }

        $migrations = $definition['migrations'] ?? [];

        if (! is_array($migrations)) {
            $errors[] = 'migrations must be a list of steps.';

            return;
        }

        // v1 has nothing to come from, so it needs no path.
        if ($version === 1) {
            if ($migrations !== []) {
                $errors[] = 'Version 1 declares migrations, but there is no earlier version to migrate from.';
            }

            return;
        }

        $froms = [];

        foreach ($migrations as $step) {
            if (! is_array($step)) {
                $errors[] = 'Each migration step must be a map with a "from" key.';

                continue;
            }

            $from = $step['from'] ?? null;

            if (! is_int($from) || $from < 1 || $from >= $version) {
                $errors[] = sprintf(
                    'Invalid migration "from" value %s: must be an integer between 1 and %d.',
                    is_scalar($from) ? (string) $from : gettype($from),
                    $version - 1,
                );

                continue;
            }

            if (isset($froms[$from])) {
                $errors[] = sprintf('Duplicate migration step from version %d.', $from);
            }

            $froms[$from] = true;

            if (Migration::fromArray($step)->declaresNothing()) {
                $errors[] = sprintf(
                    'Migration step from version %d declares no changes. '
                    .'Remove it, or say what changed (add_level, rename_level, remove_level, add_facet, remove_facet).',
                    $from,
                );
            }
        }

        // The chain must be dense: every version from 1 up to the current one
        // needs a step, or a tracker sitting on the missing version has no way
        // forward.
        $missing = [];

        for ($v = 1; $v < $version; $v++) {
            if (! isset($froms[$v])) {
                $missing[] = $v;
            }
        }

        if ($missing !== []) {
            $errors[] = sprintf(
                'Version %d declares no migration path from version(s) %s. '
                .'A version bump without a migration path is refused: a tracker running one of those versions '
                .'has no way to upgrade, and guessing what your change meant is not something an admin should have to do.',
                $version,
                implode(', ', $missing),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<string>  $errors
     */
    private function validateFacets(array $definition, array &$errors): void
    {
        $facets = $definition['facets'] ?? [];

        if (! is_array($facets)) {
            $errors[] = 'facets must be a list of vocabulary names.';

            return;
        }

        $seen = [];

        foreach ($facets as $facet) {
            if (! is_string($facet)) {
                $errors[] = 'Each facet must be a vocabulary name.';

                continue;
            }

            if (preg_match(self::IDENTIFIER, $facet) !== 1) {
                $errors[] = sprintf('Invalid facet name "%s".', $facet);
            }

            if (isset($seen[$facet])) {
                $errors[] = sprintf('Duplicate facet "%s" in this content type.', $facet);
            }

            $seen[$facet] = true;
        }
    }
}
