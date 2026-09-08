<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Marque\Taxonomy\Contracts\ClassifiesTorrents;
use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Exceptions\InvalidClassificationException;
use Marque\Taxonomy\Models\Classification;
use Marque\Taxonomy\Models\Facet;
use Marque\Taxonomy\Models\FacetValue;
use Marque\Taxonomy\Models\InstalledVersion;
use Marque\Taxonomy\Models\Term;
use Marque\Trove\Models\Torrent;

/**
 * Puts a torrent somewhere in a content type's hierarchy, and hangs facet
 * values off it.
 *
 * Two rules the definition enforces here rather than merely documenting:
 *
 * **No gaps.** A path may not skip a level. There is no "week 12" independent
 * of which season's week 12 it is — and a tree that allows the gap is the
 * inconsistent-depth mess the XenForo dump is full of (416 week-nodes at depth
 * 2, 219 at depth 3, "all week 12 games" unwritable).
 *
 * **Declared ranges are real.** `week: { range: [1, 22] }` refuses week 47. If
 * it did not, the range would be a comment and the catalogue would drift.
 */
class Classifier implements ClassifiesTorrents
{
    /**
     * @param  array<string, string>  $path  Level name to value, e.g. ['season' => '2006', 'week' => '12'].
     * @param  array<string, list<string>>  $facets  Facet name to its values.
     */
    public function classify(
        Torrent $torrent,
        ContentType $type,
        array $path,
        array $facets = [],
        ?string $groupingKey = null,
    ): Classification {
        $this->assertPathIsValid($type, $path);
        $this->assertFacetsAreDeclared($type, $facets);

        return DB::transaction(function () use ($torrent, $type, $path, $facets, $groupingKey): Classification {
            // First use of a content type pins the version this tracker is
            // running. Without that, "has a package author changed this
            // underneath me?" has nothing to compare against — and it never
            // moves on its own, so `composer update` cannot adopt a new shape
            // by itself.
            InstalledVersion::remember($type->name, $type->version);

            $term = $this->resolvePath($type, $path);

            $classification = Classification::updateOrCreate(
                // One classification per torrent per content type: reclassifying
                // is a correction, not a second opinion. Matches the unique
                // index from CP2.
                ['torrent_id' => $torrent->getKey(), 'content_type' => $type->name],
                ['term_id' => $term->getKey(), 'grouping_key' => $groupingKey],
            );

            $this->syncFacets($torrent, $type, $facets);

            return $classification->load('term.parent');
        });
    }

    /**
     * The other torrents sharing this one's grouping key — four encodes of one
     * game.
     *
     * Core groups; it never interprets the key. What makes two torrents "the
     * same thing" is the domain package's business.
     *
     * @return Collection<int, Torrent>
     */
    public function siblings(Torrent $torrent): Collection
    {
        $keys = Classification::where('torrent_id', $torrent->getKey())
            ->whereNotNull('grouping_key')
            ->pluck('grouping_key');

        if ($keys->isEmpty()) {
            return new Collection;
        }

        return Torrent::query()
            ->whereIn('id', Classification::whereIn('grouping_key', $keys)
                ->where('torrent_id', '!=', $torrent->getKey())
                ->select('torrent_id'))
            ->get();
    }

    /**
     * @param  array<string, string>  $path
     */
    private function assertPathIsValid(ContentType $type, array $path): void
    {
        if ($path === []) {
            throw InvalidClassificationException::empty($type->name);
        }

        foreach ($path as $level => $value) {
            if ($type->level($level) === null) {
                throw InvalidClassificationException::unknownLevel($level, $type->name);
            }

            if (! $type->level($level)->accepts($value)) {
                throw InvalidClassificationException::rejectedValue($level, $value);
            }
        }

        // Walk the declared levels in order: everything up to the deepest one
        // supplied must be present. Stopping short is fine (a season's
        // highlight reel is classified at the season); skipping is not.
        $seen = false;

        foreach (array_reverse($type->levels) as $level) {
            if (isset($path[$level->name])) {
                $seen = true;

                continue;
            }

            if ($seen) {
                throw InvalidClassificationException::gap($level->name, $this->deepestSupplied($type, $path));
            }
        }
    }

    /**
     * @param  array<string, string>  $path
     */
    private function deepestSupplied(ContentType $type, array $path): string
    {
        $deepest = '';

        foreach ($type->levels as $level) {
            if (isset($path[$level->name])) {
                $deepest = $level->name;
            }
        }

        return $deepest;
    }

    /**
     * @param  array<string, list<string>>  $facets
     */
    private function assertFacetsAreDeclared(ContentType $type, array $facets): void
    {
        foreach (array_keys($facets) as $facet) {
            if (! in_array($facet, $type->facets, true)) {
                throw InvalidClassificationException::unknownFacet((string) $facet, $type->name);
            }
        }
    }

    /**
     * Walk the path, creating terms that do not exist yet and reusing those
     * that do.
     *
     * Reuse is not an optimisation — two torrents in the same week must share
     * one week term, or the hierarchy degenerates into a per-torrent list and
     * every cross-cutting query stops working.
     *
     * @param  array<string, string>  $path
     */
    private function resolvePath(ContentType $type, array $path): Term
    {
        $parent = null;

        foreach ($type->levels as $level) {
            if (! isset($path[$level->name])) {
                break;
            }

            $parent = Term::firstOrCreate(
                [
                    'content_type' => $type->name,
                    'level' => $level->name,
                    'parent_key' => $parent?->getKey() ?? 0,
                    'value' => $path[$level->name],
                ],
                [
                    'parent_id' => $parent?->getKey(),
                    'path' => $parent?->pathForChildren() ?? '/',
                    'label' => $path[$level->name],
                ],
            );
        }

        // assertPathIsValid guarantees at least one level was supplied.
        return $parent;
    }

    /**
     * @param  array<string, list<string>>  $facets
     */
    private function syncFacets(Torrent $torrent, ContentType $type, array $facets): void
    {
        $ids = [];

        foreach ($facets as $name => $values) {
            // Created on demand: a facet named in a definition should not also
            // need a separate seeding step before anything can use it.
            $facet = Facet::firstOrCreate(
                ['name' => $name],
                ['label' => ucfirst(str_replace('_', ' ', (string) $name))],
            );

            foreach ($values as $value) {
                $ids[] = FacetValue::firstOrCreate(
                    ['facet_id' => $facet->getKey(), 'value' => $value],
                    ['label' => $value],
                )->getKey();
            }
        }

        // sync() rather than attach(): reclassifying replaces the facet values
        // rather than accumulating them, so correcting a mistake actually
        // corrects it.
        $torrent->taxonomyFacetValues()->sync($ids);
    }
}
