<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Livewire;

use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Models\Classification;
use Marque\Taxonomy\Models\Facet;
use Marque\Taxonomy\Models\FacetValue;
use Marque\Taxonomy\Models\InstalledVersion;
use Marque\Taxonomy\Models\Term;
use Marque\Taxonomy\Services\Upgrader;
use Marque\Trove\Enums\Role;

/**
 * Where an admin populates a taxonomy — and, deliberately, cannot design one.
 *
 * The Spec's goal was *"the power lives in the package, the admin experience
 * is a form"*. So this screen adds values to levels that a definition already
 * declared, and offers no way whatsoever to add, rename or remove a level.
 *
 * That absence is the design. Hand-built trees produce the 2018 XenForo dump
 * (416 week-nodes at depth 2, 219 at depth 3, "all week 12 games" unwritable);
 * flat lists produce the legacy `categories` table (72 rows, `parent` unused,
 * the season baked into every name). An admin who can only add values cannot
 * reach either failure.
 *
 * Structural change goes through CP5's commands, which report what they will
 * orphan and demand confirmation. This screen *names* those commands rather
 * than reimplementing them: the confirmation is deliberately a command, not a
 * button.
 */
class TaxonomyAdmin extends Component
{
    public string $contentType = '';

    /** @var array<string, mixed> */
    public array $newTerm = ['level' => '', 'value' => '', 'parent' => null];

    /** @var array<string, string> */
    public array $newFacetValue = ['facet' => '', 'value' => ''];

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    /**
     * Editing the shape of a catalogue is an admin action.
     *
     * Checked on mount and again on every write, rather than trusted to
     * whatever route the consumer happens to hang this on — a package cannot
     * assume its host wired middleware correctly.
     */
    protected function authorizeAdmin(): void
    {
        $user = auth()->user();

        if ($user === null || ! method_exists($user, 'hasRoleAtLeast') || ! $user->hasRoleAtLeast(Role::Admin)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @return array<string, ContentType>
     */
    #[Computed]
    public function types(): array
    {
        return app(Loader::class)->load();
    }

    #[Computed]
    public function definition(): ?ContentType
    {
        return $this->types()[$this->contentType] ?? null;
    }

    /**
     * What is installed, from where, at what version — plus any pending
     * upgrade, named as the command that applies it.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function installed(): array
    {
        $pending = collect(app(Upgrader::class)->pending(array_values($this->types())))
            ->keyBy('content_type');

        $rows = [];

        foreach ($this->types() as $name => $type) {
            $rows[] = [
                'name' => $name,
                'label' => $type->label,
                'version' => $type->version,
                'installed' => InstalledVersion::for($name)?->version,
                'levels' => implode(' → ', $type->levelNames()),
                'classified' => Classification::where('content_type', $name)->count(),
                'pending' => $pending->get($name),
            ];
        }

        return $rows;
    }

    /**
     * The terms already declared for the chosen content type, grouped by level.
     *
     * @return array<string, list<Term>>
     */
    #[Computed]
    public function terms(): array
    {
        $type = $this->definition();

        if ($type === null) {
            return [];
        }

        $grouped = [];

        foreach ($type->levels as $level) {
            $grouped[$level->name] = Term::query()
                ->where('content_type', $type->name)
                ->where('level', $level->name)
                ->orderBy('value')
                ->get()
                ->all();
        }

        return $grouped;
    }

    /**
     * Every facet any definition declares, with its current values.
     *
     * Vocabularies are shared across content types, so this is not scoped to
     * the chosen one.
     *
     * @return array<string, list<FacetValue>>
     */
    #[Computed]
    public function vocabularies(): array
    {
        $declared = [];

        foreach ($this->types() as $type) {
            foreach ($type->facets as $facet) {
                $declared[$facet] = true;
            }
        }

        $vocabularies = [];

        foreach (array_keys($declared) as $name) {
            $vocabularies[$name] = Facet::query()
                ->where('name', $name)
                ->first()
                ?->values()
                ->orderBy('value')
                ->get()
                ->all() ?? [];
        }

        return $vocabularies;
    }

    public function addTerm(): void
    {
        $this->authorizeAdmin();

        $type = $this->definition();

        if ($type === null) {
            $this->addError('contentType', 'Choose a content type first.');

            return;
        }

        $level = $type->level((string) ($this->newTerm['level'] ?? ''));

        // The guarantee, enforced rather than assumed: a level the definition
        // does not declare cannot be populated, however the request arrived.
        if ($level === null) {
            $this->addError('newTerm.level', sprintf(
                '"%s" is not a level of %s. Levels are declared in the definition, not added here.',
                $this->newTerm['level'] ?? '',
                $type->label,
            ));

            return;
        }

        $value = trim((string) ($this->newTerm['value'] ?? ''));

        if (! $level->accepts($value)) {
            $this->addError('newTerm.value', sprintf('%s does not accept "%s".', $level->label, $value));

            return;
        }

        $parentId = $this->newTerm['parent'] === null || $this->newTerm['parent'] === ''
            ? null
            : (int) $this->newTerm['parent'];

        $parent = $parentId === null ? null : Term::find($parentId);

        $exists = Term::query()
            ->where('content_type', $type->name)
            ->where('level', $level->name)
            ->where('parent_key', $parent?->getKey() ?? 0)
            ->where('value', $value)
            ->exists();

        if ($exists) {
            $this->addError('newTerm.value', sprintf('"%s" is already there.', $value));

            return;
        }

        Term::create([
            'content_type' => $type->name,
            'level' => $level->name,
            'value' => $value,
            'label' => $value,
            'parent_id' => $parent?->getKey(),
            'parent_key' => $parent?->getKey() ?? 0,
            'path' => $parent?->pathForChildren() ?? '/',
        ]);

        $this->newTerm = ['level' => '', 'value' => '', 'parent' => null];

        unset($this->terms);
    }

    /**
     * Change a term's display label.
     *
     * Only the label: the `value` is what classifications resolve against, so
     * changing it would be a structural edit wearing a cosmetic hat.
     */
    public function renameTerm(int $id, string $label): void
    {
        $this->authorizeAdmin();

        $term = Term::find($id);

        if ($term === null) {
            $this->addError('terms', 'That term no longer exists.');

            return;
        }

        $term->update(['label' => trim($label)]);

        unset($this->terms);
    }

    /**
     * Delete a term, but only one nothing depends on.
     *
     * Anything with children or classifications is refused here and pointed at
     * the definition machinery instead. This screen never silently orphans.
     */
    public function deleteTerm(int $id): void
    {
        $this->authorizeAdmin();

        $term = Term::find($id);

        if ($term === null) {
            $this->addError('terms', 'That term no longer exists.');

            return;
        }

        $children = Term::where('parent_id', $term->getKey())->count();

        if ($children > 0) {
            $this->addError('terms', sprintf(
                '"%s" has %d term(s) beneath it. Delete those first — this screen will not orphan them.',
                $term->label ?? $term->value,
                $children,
            ));

            return;
        }

        $classified = Classification::where('term_id', $term->getKey())->count();

        if ($classified > 0) {
            $this->addError('terms', sprintf(
                '"%s" has %d torrent(s) classified under it. Reclassify them first, or change the definition and run marque:taxonomy:upgrade — that path reports what it will orphan before it does anything.',
                $term->label ?? $term->value,
                $classified,
            ));

            return;
        }

        $term->delete();

        unset($this->terms);
    }

    public function addFacetValue(): void
    {
        $this->authorizeAdmin();

        $name = trim((string) ($this->newFacetValue['facet'] ?? ''));
        $value = trim((string) ($this->newFacetValue['value'] ?? ''));

        // A vocabulary no definition declares would be structure invented
        // here, which is the same thing as inventing a level.
        if (! array_key_exists($name, $this->vocabularies())) {
            $this->addError('newFacetValue.facet', sprintf(
                'No definition declares a "%s" facet.',
                $name,
            ));

            return;
        }

        if ($value === '') {
            $this->addError('newFacetValue.value', 'Give the value a name.');

            return;
        }

        $facet = Facet::firstOrCreate(
            ['name' => $name],
            ['label' => ucfirst(str_replace('_', ' ', $name))],
        );

        if ($facet->values()->where('value', $value)->exists()) {
            $this->addError('newFacetValue.value', sprintf('"%s" is already in %s.', $value, $name));

            return;
        }

        FacetValue::create([
            'facet_id' => $facet->getKey(),
            'value' => $value,
            'label' => $value,
        ]);

        $this->newFacetValue = ['facet' => '', 'value' => ''];

        unset($this->vocabularies);
    }

    public function deleteFacetValue(int $id): void
    {
        $this->authorizeAdmin();

        $value = FacetValue::find($id);

        if ($value === null) {
            $this->addError('facets', 'That value no longer exists.');

            return;
        }

        // The schema would cascade the assignments away. That is right for a
        // definition-driven removal with a reported count behind it (CP5), and
        // wrong for a click on an admin screen.
        $assigned = $value->torrentAssignmentCount();

        if ($assigned > 0) {
            $this->addError('facets', sprintf(
                '"%s" is on %d torrent(s). Removing it here would strip it from all of them.',
                $value->value,
                $assigned,
            ));

            return;
        }

        $value->delete();

        unset($this->vocabularies);
    }

    public function render()
    {
        return view('taxonomy::livewire.taxonomy-admin');
    }
}
