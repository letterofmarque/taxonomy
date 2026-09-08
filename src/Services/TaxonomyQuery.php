<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Marque\Taxonomy\Models\Classification;
use Marque\Taxonomy\Models\Facet;
use Marque\Taxonomy\Models\Term;
use Marque\Trove\Models\Torrent;

/**
 * Filtering a catalogue by taxonomy — the requirement the whole Spec exists
 * for.
 *
 * Dan, on the legacy tracker: *"you could find NFL games on legacy and you got
 * a massive list of them... you couldn't find 2006 games or week 12 games
 * without using search."*
 *
 * What makes those answerable here is that a level is a **typed dimension**,
 * not a node at some depth. `where('week', '12')` matches every term declaring
 * itself a `week`, whether it sits at depth 1 (NFL: season → week) or depth 2
 * (NCAA: season → division → week). A tree that identifies levels by position
 * cannot express it — which is exactly the 416-at-depth-2, 219-at-depth-3 mess
 * in the XenForo dump.
 *
 * Immutable: every method returns a new instance, so a partially-built query
 * can be shared without one caller's filter leaking into another's.
 *
 * No raw SQL anywhere. Portability is non-negotiable (docs/why.md), and this
 * is the class where the temptation is highest.
 */
class TaxonomyQuery
{
    /**
     * @param  list<array{level: string, value: string}>  $levels
     * @param  list<array{facet: string, value: string}>  $facets
     * @param  list<Term>  $under
     */
    public function __construct(
        private readonly ?string $contentType = null,
        private readonly array $levels = [],
        private readonly array $facets = [],
        private readonly array $under = [],
    ) {}

    /**
     * Scope to one content type. Without this, a filter deliberately spans
     * them — "every 2006 game" means every league.
     */
    public function contentType(string $name): self
    {
        return new self($name, $this->levels, $this->facets, $this->under);
    }

    /**
     * Match a level by name and value, wherever that level sits in the tree.
     */
    public function where(string $level, string $value): self
    {
        return new self(
            $this->contentType,
            [...$this->levels, ['level' => $level, 'value' => $value]],
            $this->facets,
            $this->under,
        );
    }

    /**
     * Everything beneath a term, at any depth — a prefix match on the
     * materialised path.
     */
    public function under(Term $term): self
    {
        return new self($this->contentType, $this->levels, $this->facets, [...$this->under, $term]);
    }

    /**
     * Torrents carrying this value of this facet.
     *
     * The facet name is part of the filter rather than decoration: `web` may
     * exist in two vocabularies, and matching one must not find the other.
     */
    public function withFacet(string $facet, string $value): self
    {
        return new self(
            $this->contentType,
            $this->levels,
            [...$this->facets, ['facet' => $facet, 'value' => $value]],
            $this->under,
        );
    }

    /**
     * @return Collection<int, Torrent>
     */
    public function get(): Collection
    {
        return $this->applyTo(Torrent::query())->get();
    }

    public function count(): int
    {
        return $this->applyTo(Torrent::query())->count();
    }

    /**
     * Apply these filters to an existing torrent query.
     *
     * The seam that matters for composition: trove's own scoping (viewer
     * visibility, dead-torrent filtering) has to survive a taxonomy filter, or
     * a caller is forced to choose between classification and permissions.
     *
     * @param  Builder<Torrent>  $query
     * @return Builder<Torrent>
     */
    public function applyTo(Builder $query): Builder
    {
        // whereIn against a subquery rather than a join: a join against
        // many-to-many assignments fans out, returning one row per matching
        // assignment, and the distinct() needed to undo that interacts badly
        // with ordering across engines. Subqueries stay flat and portable.
        foreach ($this->levels as $filter) {
            $query->whereIn('id', $this->torrentIdsForLevel($filter['level'], $filter['value']));
        }

        foreach ($this->facets as $filter) {
            $query->whereIn('id', $this->torrentIdsForFacet($filter['facet'], $filter['value']));
        }

        foreach ($this->under as $term) {
            $query->whereIn('id', $this->torrentIdsUnder($term));
        }

        if ($this->contentType !== null) {
            $query->whereIn('id', Classification::query()
                ->where('content_type', $this->contentType)
                ->select('torrent_id'));
        }

        return $query;
    }

    /**
     * Torrents classified at a term with this level and value, or anywhere
     * beneath one.
     *
     * The "or beneath" half is what makes `season = 2006` find a torrent
     * classified at week 12 of 2006 — the torrent sits at the leaf, but it is
     * still a 2006 game.
     */
    private function torrentIdsForLevel(string $level, string $value)
    {
        $terms = Term::query()
            ->where('level', $level)
            ->where('value', $value)
            ->when($this->contentType !== null, fn ($q) => $q->where('content_type', $this->contentType))
            ->get();

        $ids = $terms->pluck('id')->all();

        // Nothing matched, so nothing can be beneath it. Returning early
        // matters: an empty where() group matches every row, which would
        // silently degrade the filter into no filter at all — the failure mode
        // where "week 19" returns the whole catalogue instead of nothing.
        // `whereIn` on an empty set rather than a raw `0 = 1`: no raw SQL
        // anywhere in this class, because portability across four engines is
        // non-negotiable (docs/why.md) and this is where the temptation is
        // highest.
        if ($terms->isEmpty()) {
            return Classification::query()->whereIn('term_id', [])->select('torrent_id');
        }

        $descendants = Term::query()->where(function ($query) use ($terms): void {
            foreach ($terms as $term) {
                $query->orWhere('path', 'like', $term->pathForChildren().'%');
            }
        })->pluck('id')->all();

        return Classification::query()
            ->whereIn('term_id', [...$ids, ...$descendants])
            ->select('torrent_id');
    }

    private function torrentIdsUnder(Term $term)
    {
        $ids = Term::query()
            ->where('path', 'like', $term->pathForChildren().'%')
            ->pluck('id')
            ->all();

        return Classification::query()
            ->whereIn('term_id', [...$ids, $term->getKey()])
            ->select('torrent_id');
    }

    private function torrentIdsForFacet(string $facet, string $value)
    {
        return DB::table('taxonomy_assignments')
            ->whereIn('facet_value_id', DB::table('taxonomy_facet_values')
                ->where('value', $value)
                ->whereIn('facet_id', Facet::query()->where('name', $facet)->select('id'))
                ->select('id'))
            ->select('torrent_id');
    }
}
