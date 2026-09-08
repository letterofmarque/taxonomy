<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Services;

/**
 * What an edit would cost, in rows.
 *
 * Deliberately a plain value object holding counts rather than a
 * yes/no judgement: the command shows these numbers to the admin, and
 * "3 torrents" is what makes the decision real.
 */
final class Drift
{
    /**
     * @param  list<string>  $removedLevels
     * @param  list<string>  $removedFacets
     */
    public function __construct(
        public readonly string $contentType,
        public readonly array $removedLevels = [],
        public readonly array $removedFacets = [],
        public readonly int $orphanedTerms = 0,
        public readonly int $orphanedTorrents = 0,
        public readonly int $unassignedValues = 0,
    ) {}

    /**
     * Would this edit orphan anything?
     *
     * True even when the counts are zero — removing an unused level is still
     * structurally destructive, and the admin should be told it is happening
     * even though it is free. The counts then say how much it actually costs.
     */
    public function isDestructive(): bool
    {
        return $this->removedLevels !== [] || $this->removedFacets !== [];
    }
}
