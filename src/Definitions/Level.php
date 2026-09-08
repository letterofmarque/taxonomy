<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Definitions;

/**
 * One level of a content type's hierarchy — Season, Week, Stage, Episode.
 *
 * A level is a **typed dimension**, not merely a node in a tree, and that is
 * the distinction the whole Spec turns on. Because `week` is a dimension
 * scoped to a content type, "all week 12 games" is expressible across every
 * season and every league. In a free-form tree it is not, which is precisely
 * what the legacy tracker could not do.
 */
final class Level
{
    /**
     * The types a level may declare. Closed deliberately: an unknown type is a
     * validation error rather than a silently-accepted string, because a typo
     * that loads is a typo that breaks a catalogue quietly.
     */
    public const TYPES = ['string', 'integer', 'year'];

    /**
     * Types for which a range is meaningful. A range on a string level is
     * almost certainly a mistake, so it is refused rather than ignored.
     */
    public const RANGEABLE = ['integer', 'year'];

    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly int $depth,
        public readonly ?int $min = null,
        public readonly ?int $max = null,
    ) {}

    /**
     * @param  array<string, mixed>  $declaration
     */
    public static function fromArray(string $name, array $declaration, int $depth): self
    {
        $range = $declaration['range'] ?? null;

        return new self(
            name: $name,
            // A missing label is cosmetic, not broken — fall back to the name
            // rather than making every definition spell it out.
            label: is_string($declaration['label'] ?? null) ? $declaration['label'] : $name,
            type: is_string($declaration['type'] ?? null) ? $declaration['type'] : 'string',
            depth: $depth,
            min: is_array($range) && isset($range[0]) && is_numeric($range[0]) ? (int) $range[0] : null,
            max: is_array($range) && isset($range[1]) && is_numeric($range[1]) ? (int) $range[1] : null,
        );
    }

    /**
     * Would this level accept the given value?
     *
     * Used when classifying a torrent, so a definition's declared range is a
     * real constraint rather than documentation.
     */
    public function accepts(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        return match ($this->type) {
            'integer' => $this->acceptsInteger($value),
            'year' => $this->acceptsYear($value),
            default => true,
        };
    }

    private function acceptsInteger(string $value): bool
    {
        if (! ctype_digit(ltrim($value, '-'))) {
            return false;
        }

        $int = (int) $value;

        if ($this->min !== null && $int < $this->min) {
            return false;
        }

        return ! ($this->max !== null && $int > $this->max);
    }

    private function acceptsYear(string $value): bool
    {
        // Four digits. Deliberately not bounded to "plausible" years — a
        // tracker cataloguing historical footage may legitimately want 1888,
        // and guessing an upper bound would age badly.
        if (! ctype_digit($value) || strlen($value) !== 4) {
            return false;
        }

        $year = (int) $value;

        if ($this->min !== null && $year < $this->min) {
            return false;
        }

        return ! ($this->max !== null && $year > $this->max);
    }
}
