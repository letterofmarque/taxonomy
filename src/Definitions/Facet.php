<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Definitions;

/**
 * A facet vocabulary — resolution, source, codec, subtitles.
 *
 * Facets are shared across content types, which is what distinguishes them
 * from levels. `resolution` means the same thing on a film tracker and a
 * sports tracker; `week` does not exist outside the content type that declared
 * it.
 *
 * Cardinality lives here rather than in the schema (resolved open question 1).
 * Storage is many-to-many unconditionally; a definition may declare a facet
 * single-valued as a constraint on top of that. Dan's counter-example settled
 * it: "netflix rips these days will often have half a dozen or even more audio
 * tracks and even more subtitle tracks."
 */
final class Facet
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly bool $single = false,
    ) {}
}
