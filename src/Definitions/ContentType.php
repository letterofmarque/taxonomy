<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Definitions;

/**
 * A content type — the unit of scoping, and the decision the Spec turns on.
 *
 * Considered and rejected: one shared term table with typed dimensions in a
 * single namespace. It fails the multi-sport test directly — an admin running
 * NFL, cricket, swimming and cycling would face one flat list containing
 * Week, Day, Heat, Stage, Round and Innings, and have to know which applied
 * where. Scoping is what makes `Week` on NFL Game and `Week` on a TV type
 * independent, and what stops a cycling uploader ever seeing `Week`.
 *
 * Authored as YAML, but this object is ordinary PHP with no framework
 * coupling — a pure seam, which is why it composes cleanly (Spec #83).
 */
final class ContentType
{
    /**
     * @param  list<Level>  $levels
     * @param  list<string>  $facets
     * @param  list<Migration>  $migrations
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly int $version,
        public readonly array $levels,
        public readonly array $facets = [],
        public readonly array $migrations = [],
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function fromArray(array $definition): self
    {
        $levels = [];
        $depth = 0;

        // Levels arrive as an ordered list of single-key maps, because order
        // IS the hierarchy — season contains week, not the reverse. A plain
        // map would leave that to the YAML parser's key ordering, which is not
        // something to rely on.
        foreach ($definition['levels'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($entry as $name => $declaration) {
                $levels[] = Level::fromArray(
                    (string) $name,
                    is_array($declaration) ? $declaration : [],
                    $depth++,
                );
            }
        }

        $name = (string) ($definition['content_type'] ?? '');

        return new self(
            name: $name,
            label: is_string($definition['label'] ?? null) ? $definition['label'] : $name,
            // Every definition has a version whether the author wrote one or
            // not. CP5 compares against it, and a null would make "has this
            // changed?" unanswerable.
            version: is_int($definition['version'] ?? null) ? $definition['version'] : 1,
            levels: $levels,
            facets: array_values(array_filter(
                $definition['facets'] ?? [],
                is_string(...),
            )),
            migrations: array_map(
                Migration::fromArray(...),
                array_values(array_filter(
                    $definition['migrations'] ?? [],
                    is_array(...),
                )),
            ),
        );
    }

    /**
     * The ordered steps taking an installed version up to this one.
     *
     * Returns an empty array when already current, and **null when the chain
     * is broken** — a tracker on v1 with only a v2→v3 step declared cannot be
     * upgraded, and pretending otherwise would silently skip a step and leave
     * the catalogue in a shape nobody described.
     *
     * @return list<Migration>|null
     */
    public function migrationPathFrom(int $installed): ?array
    {
        if ($installed >= $this->version) {
            return [];
        }

        $steps = [];

        for ($v = $installed; $v < $this->version; $v++) {
            $step = null;

            foreach ($this->migrations as $migration) {
                if ($migration->from === $v) {
                    $step = $migration;

                    break;
                }
            }

            if ($step === null) {
                return null;
            }

            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * @return list<string>
     */
    public function levelNames(): array
    {
        return array_map(fn (Level $level): string => $level->name, $this->levels);
    }

    public function level(string $name): ?Level
    {
        foreach ($this->levels as $level) {
            if ($level->name === $name) {
                return $level;
            }
        }

        return null;
    }

    /**
     * The level below the given one, or the first level when given null.
     *
     * This is what drives the cascading dependent selects on the upload form
     * (resolved open question 4): pick NFL, get seasons; pick a season, get
     * weeks.
     */
    public function levelAfter(?string $name): ?Level
    {
        if ($name === null) {
            return $this->levels[0] ?? null;
        }

        foreach ($this->levels as $index => $level) {
            if ($level->name === $name) {
                return $this->levels[$index + 1] ?? null;
            }
        }

        return null;
    }
}
