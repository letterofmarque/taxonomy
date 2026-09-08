<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Component;
use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Exceptions\InvalidClassificationException;
use Marque\Taxonomy\Models\Facet;
use Marque\Taxonomy\Models\Term;
use Marque\Taxonomy\Services\Classifier;
use Marque\Trove\Models\Torrent;

/**
 * Classify a torrent at upload: pick a content type, then walk its hierarchy
 * one level at a time.
 *
 * Dan, resolving open question 4: *"you pick NFL and then the next dropdown
 * becomes the relevant NFL seasons etc and then choose one and we get weeks."*
 *
 * More interactions than legacy's single 72-item dropdown, and that is the
 * right trade. Legacy's `categories` table had a `parent` column that went
 * unused across all 72 rows — the season got baked into the name instead,
 * which is how you end up unable to ask for "all 2006 games". A cascade makes
 * correct nesting the path of least resistance rather than something an admin
 * has to be disciplined about.
 *
 * **This component is optional.** `livewire/livewire` is a suggest rather than
 * a require, and the provider only registers this when Livewire is installed —
 * a `class_exists` check on a PHP class, which composes, as against a Blade
 * component guard, which does not (Spec #83). An API-only consumer classifies
 * through the `Classifier` service and never loads any of this.
 *
 * The markup is owned here rather than built from `ise` components, for the
 * same reason: taking a UI-kit dependency would make the engine uninstallable
 * without a frontend.
 */
class ClassifierForm extends Component
{
    public Torrent $torrent;

    public string $contentType = '';

    /** @var array<string, string|null> */
    public array $path = [];

    /** @var array<string, list<string>> */
    public array $facets = [];

    public ?string $groupingKey = null;

    public function mount(Torrent $torrent): void
    {
        $this->torrent = $torrent;

        // Reopening a classified torrent shows what it already is. An empty
        // form that silently reclassified on save would be a trap.
        $existing = $torrent->taxonomyClassifications()->with('term')->first();

        if ($existing === null) {
            return;
        }

        $this->contentType = $existing->content_type;
        $this->groupingKey = $existing->grouping_key;

        for ($term = $existing->term; $term !== null; $term = $term->parent) {
            $this->path[$term->level] = $term->value;
        }
    }

    /**
     * Changing the content type abandons a part-built path.
     *
     * Keeping it would leave a season chosen under NFL sitting in a form now
     * showing cricket's levels — a path half-belonging to two types.
     */
    public function updatedContentType(): void
    {
        $this->path = [];
        $this->facets = [];
    }

    /**
     * Changing a level clears everything below it.
     *
     * Week 12 belongs to 2006. Switching to 2007 while week 12 stays selected
     * would submit a path that never existed.
     */
    public function updated(string $property): void
    {
        if (! str_starts_with($property, 'path.')) {
            return;
        }

        $changed = substr($property, 5);
        $type = $this->definition();

        if ($type === null) {
            return;
        }

        $seen = false;

        foreach ($type->levels as $level) {
            if ($seen) {
                $this->path[$level->name] = null;
            }

            if ($level->name === $changed) {
                $seen = true;
            }
        }
    }

    /**
     * The options for each level, as far down as the path has been filled in.
     *
     * Only one level beyond the deepest choice is offered: that is what makes
     * it a cascade rather than a wall of selects.
     *
     * @return array<string, list<string>>
     */
    #[Computed]
    public function options(): array
    {
        $type = $this->definition();

        if ($type === null) {
            return [];
        }

        $options = [];
        $parentId = 0;

        foreach ($type->levels as $level) {
            $options[$level->name] = Term::query()
                ->where('content_type', $type->name)
                ->where('level', $level->name)
                ->where('parent_key', $parentId)
                ->orderBy('value')
                ->pluck('value')
                ->all();

            $chosen = $this->path[$level->name] ?? null;

            if ($chosen === null || $chosen === '') {
                break;
            }

            $parent = Term::query()
                ->where('content_type', $type->name)
                ->where('level', $level->name)
                ->where('parent_key', $parentId)
                ->where('value', $chosen)
                ->first();

            // A value the uploader typed that does not exist yet — a tracker's
            // first 2008 game, or the very first upload on an empty tracker.
            //
            // The next level still has to be offered, empty. Stopping here
            // instead would make a new tracker unusable: nobody could get past
            // the first level, because no term exists to be anyone's parent.
            // The datalist is simply empty and the uploader types the value.
            if ($parent === null) {
                $remaining = $type->levelAfter($level->name);

                if ($remaining !== null) {
                    $options[$remaining->name] = [];
                }

                break;
            }

            $parentId = $parent->getKey();
        }

        return $options;
    }

    /**
     * @return array<string, list<string>>
     */
    #[Computed]
    public function facetOptions(): array
    {
        $type = $this->definition();

        if ($type === null) {
            return [];
        }

        $options = [];

        foreach ($type->facets as $name) {
            $options[$name] = Facet::query()
                ->where('name', $name)
                ->first()
                ?->values()
                ->orderBy('value')
                ->pluck('value')
                ->all() ?? [];
        }

        return $options;
    }

    public function save(): void
    {
        $type = $this->definition();

        if ($type === null) {
            $this->addError('contentType', 'Choose what kind of thing this is.');

            return;
        }

        $path = array_filter(
            $this->path,
            fn (?string $value): bool => $value !== null && $value !== '',
        );

        // Validated against the definition rather than a static rule set: the
        // declared type and range ARE the validation, and they differ per
        // content type. 14 is a valid cycling stage and an invalid cricket
        // innings.
        foreach ($path as $name => $value) {
            $level = $type->level((string) $name);

            if ($level === null) {
                continue;
            }

            if (! $level->accepts((string) $value)) {
                $this->addError('path.'.$name, sprintf(
                    '%s does not accept "%s".',
                    $level->label,
                    $value,
                ));
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        // A facet the type does not declare is dropped rather than rejected: a
        // stale field left over from a previous type selection should not
        // block an otherwise valid upload.
        $facets = array_intersect_key(
            array_map(
                fn (array $values): array => array_values(array_filter($values, fn ($v): bool => $v !== null && $v !== '')),
                array_filter($this->facets, is_array(...)),
            ),
            array_flip($type->facets),
        );

        try {
            app(Classifier::class)->classify(
                $this->torrent,
                $type,
                $path,
                $facets,
                $this->groupingKey === '' ? null : $this->groupingKey,
            );
        } catch (InvalidClassificationException $e) {
            // The service is the authority, not this form. Anything it refuses
            // — a gap in the path, an empty classification — surfaces here
            // rather than becoming a 500.
            $this->addError('path', $e->getMessage());

            return;
        }

        $this->dispatch('taxonomy-classified');
    }

    public function render()
    {
        return view('taxonomy::livewire.classifier-form', [
            'types' => $this->types(),
            'definition' => $this->definition(),
        ]);
    }

    /**
     * @return array<string, ContentType>
     */
    protected function types(): array
    {
        return app(Loader::class)->load();
    }

    protected function definition(): ?ContentType
    {
        return $this->types()[$this->contentType] ?? null;
    }
}
