<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Definitions;

/**
 * One declared step from an older version of a content type to the next.
 *
 * The forcing function of the whole upgrade mechanism. Dan: *"should a
 * migration path/process be mandated for that, the structure SHOULD be
 * known?"* — yes, and that reasoning is the answer. The package author is the
 * only person who knows both the old shape and the new one; making the admin
 * infer what the author's change means is backwards.
 *
 * **Rename is the case that justifies it.** `week` becoming `round` is
 * invisible to a diff-free loader: a level vanishes, another appears, and
 * every classification orphans. Declared, every one survives.
 */
final class Migration
{
    /**
     * @param  array<string, array<string, mixed>>  $addedLevels
     * @param  list<array{from: string, to: string}>  $renamedLevels
     * @param  list<string>  $removedLevels
     * @param  list<string>  $addedFacets
     * @param  list<string>  $removedFacets
     */
    public function __construct(
        public readonly int $from,
        public readonly array $addedLevels = [],
        public readonly array $renamedLevels = [],
        public readonly array $removedLevels = [],
        public readonly array $addedFacets = [],
        public readonly array $removedFacets = [],
    ) {}

    /**
     * A step goes from its declared `from` to the next version up. Chains are
     * dense by validation, so this needs no separate `to`.
     *
     * A method rather than a property hook: hooks are PHP 8.4 and the floor
     * here is 8.3 (job #10615). CI runs both ends of the range, so this would
     * have been caught — but not before wasting a run.
     */
    public function to(): int
    {
        return $this->from + 1;
    }

    /**
     * @param  array<string, mixed>  $declaration
     */
    public static function fromArray(array $declaration): self
    {
        $renames = [];
        $rename = $declaration['rename_level'] ?? null;

        if (is_array($rename) && isset($rename['from'], $rename['to'])) {
            $renames[] = ['from' => (string) $rename['from'], 'to' => (string) $rename['to']];
        }

        return new self(
            from: is_int($declaration['from'] ?? null) ? $declaration['from'] : 0,
            addedLevels: is_array($declaration['add_level'] ?? null) ? $declaration['add_level'] : [],
            renamedLevels: $renames,
            removedLevels: array_values(array_filter(
                (array) ($declaration['remove_level'] ?? []),
                is_string(...),
            )),
            addedFacets: array_values(array_filter(
                (array) ($declaration['add_facet'] ?? []),
                is_string(...),
            )),
            removedFacets: array_values(array_filter(
                (array) ($declaration['remove_facet'] ?? []),
                is_string(...),
            )),
        );
    }

    /**
     * Does this step orphan anything?
     *
     * Ceremony scales with damage: adding a nullable level is a one-line
     * declaration, removing one makes the author say what happens to the data.
     *
     * A **rename is not destructive** — that is the entire point of declaring
     * it. The classifications survive because the level is carried across
     * rather than dropped and recreated.
     */
    public function isDestructive(): bool
    {
        return $this->removedLevels !== [] || $this->removedFacets !== [];
    }

    public function declaresNothing(): bool
    {
        return $this->addedLevels === []
            && $this->renamedLevels === []
            && $this->removedLevels === []
            && $this->addedFacets === []
            && $this->removedFacets === [];
    }
}
