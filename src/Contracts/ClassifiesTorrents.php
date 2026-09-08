<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Exceptions\InvalidClassificationException;
use Marque\Taxonomy\Models\Classification;
use Marque\Trove\Models\Torrent;

/**
 * The classification seam.
 *
 * PHP-side rather than view-layer, which is what makes it composable at all:
 * Spec #83 found that Blade resolves components at *compile* time, so a
 * `class_exists()` guard around a view does not save a consumer from a missing
 * package. An interface does.
 *
 * A consumer that wants classification depends on this; one that does not,
 * does not install marque/taxonomy and nothing in trove notices.
 */
interface ClassifiesTorrents
{
    /**
     * Place a torrent in a content type's hierarchy and attach its facet
     * values.
     *
     * @param  array<string, string>  $path  Level name to value.
     * @param  array<string, list<string>>  $facets  Facet name to its values.
     *
     * @throws InvalidClassificationException when the
     *                                        path skips a level, names something the content type does not declare, or
     *                                        carries a value outside a declared range.
     */
    public function classify(
        Torrent $torrent,
        ContentType $type,
        array $path,
        array $facets = [],
        ?string $groupingKey = null,
    ): Classification;

    /**
     * Torrents sharing this one's grouping key — the other encodes of the same
     * underlying thing.
     *
     * @return Collection<int, Torrent>
     */
    public function siblings(Torrent $torrent): Collection;
}
