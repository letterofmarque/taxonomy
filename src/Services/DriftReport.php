<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Services;

use Illuminate\Support\Facades\DB;
use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Models\Classification;
use Marque\Taxonomy\Models\Facet;
use Marque\Taxonomy\Models\Term;

/**
 * What an edited definition would do to the data already classified under it.
 *
 * The admin edited their own file — no version bump, no package author
 * involved. Additive changes apply on load with no ceremony; destructive ones
 * are detected here, counted, and refused until confirmed.
 *
 * **The counts are the point.** "This will orphan 3 torrents" is a decision an
 * admin can actually make. "Are you sure?" is not — it is the dialog everyone
 * clicks through, which is how the thing this protects against happens anyway.
 */
class DriftReport
{
    /**
     * Compare an edited definition against what is currently in the database.
     */
    public function for(ContentType $edited): Drift
    {
        $liveLevels = Term::query()
            ->where('content_type', $edited->name)
            ->distinct()
            ->pluck('level')
            ->all();

        $removedLevels = array_values(array_diff($liveLevels, $edited->levelNames()));

        $liveFacets = $this->facetsInUseBy($edited->name);
        $removedFacets = array_values(array_diff($liveFacets, $edited->facets));

        return new Drift(
            contentType: $edited->name,
            removedLevels: $removedLevels,
            removedFacets: $removedFacets,
            orphanedTerms: $this->countTerms($edited->name, $removedLevels),
            orphanedTorrents: $this->countTorrents($edited->name, $removedLevels),
            unassignedValues: $this->countAssignments($edited->name, $removedFacets),
        );
    }

    /**
     * A content type that has vanished from the definitions entirely.
     *
     * From the data's point of view a rename is exactly this: the old type
     * stopped existing, and every classification under it orphans. Which is
     * why the Spec treats renaming a content type as destructive rather than
     * cosmetic.
     */
    public function forRemovedContentType(string $name): Drift
    {
        $levels = Term::query()->where('content_type', $name)->distinct()->pluck('level')->all();

        return new Drift(
            contentType: $name,
            removedLevels: $levels,
            removedFacets: $this->facetsInUseBy($name),
            orphanedTerms: Term::where('content_type', $name)->count(),
            orphanedTorrents: Classification::where('content_type', $name)->whereNotNull('term_id')->count(),
            unassignedValues: 0,
        );
    }

    /**
     * @return list<string>
     */
    private function facetsInUseBy(string $contentType): array
    {
        // Facet vocabularies are shared, so "in use by this content type"
        // means "assigned to a torrent this type has classified", not
        // "existing".
        return Facet::query()
            ->whereIn('id', DB::table('taxonomy_facet_values')
                ->whereIn('id', DB::table('taxonomy_assignments')
                    ->whereIn('torrent_id', Classification::query()
                        ->where('content_type', $contentType)
                        ->select('torrent_id'))
                    ->select('facet_value_id'))
                ->select('facet_id'))
            ->pluck('name')
            ->all();
    }

    /**
     * @param  list<string>  $removedLevels
     */
    private function countTerms(string $contentType, array $removedLevels): int
    {
        if ($removedLevels === []) {
            return 0;
        }

        return Term::query()
            ->where('content_type', $contentType)
            ->whereIn('level', $removedLevels)
            ->count();
    }

    /**
     * @param  list<string>  $removedLevels
     */
    private function countTorrents(string $contentType, array $removedLevels): int
    {
        if ($removedLevels === []) {
            return 0;
        }

        return Classification::query()
            ->where('content_type', $contentType)
            ->whereIn('term_id', Term::query()
                ->where('content_type', $contentType)
                ->whereIn('level', $removedLevels)
                ->select('id'))
            ->count();
    }

    /**
     * @param  list<string>  $removedFacets
     */
    private function countAssignments(string $contentType, array $removedFacets): int
    {
        if ($removedFacets === []) {
            return 0;
        }

        return DB::table('taxonomy_assignments')
            ->whereIn('torrent_id', Classification::query()
                ->where('content_type', $contentType)
                ->select('torrent_id'))
            ->whereIn('facet_value_id', DB::table('taxonomy_facet_values')
                ->whereIn('facet_id', Facet::query()->whereIn('name', $removedFacets)->select('id'))
                ->select('id'))
            ->count();
    }
}
