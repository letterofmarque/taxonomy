<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Exceptions;

use RuntimeException;

/**
 * A definition file could not be loaded, so nothing was loaded.
 *
 * Thrown rather than logged-and-skipped deliberately. A half-loaded taxonomy
 * breaks a catalogue in a way an admin cannot diagnose — torrents classified
 * under a level that has silently stopped existing. Refusing the whole load
 * leaves the tracker running on its previous definitions, which is a state
 * someone can actually reason about.
 *
 * The message always names the file, because "your taxonomy is broken" without
 * a filename is the same problem one level up.
 */
final class InvalidDefinitionException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public static function invalid(string $file, array $errors): self
    {
        return new self(sprintf(
            "Invalid taxonomy definition in %s:\n  - %s",
            $file,
            implode("\n  - ", $errors),
        ));
    }

    public static function unparsable(string $file, string $reason): self
    {
        return new self(sprintf('Could not parse taxonomy definition %s: %s', $file, $reason));
    }

    public static function notAMap(string $file): self
    {
        return new self(sprintf(
            'Taxonomy definition %s must be a map of keys (content_type, label, levels), not a list or scalar.',
            $file,
        ));
    }

    public static function duplicate(string $contentType, string $first, string $second): self
    {
        return new self(sprintf(
            'Content type "%s" is declared twice in the same source: %s and %s. Which one wins is undefined, so neither is loaded.',
            $contentType,
            $first,
            $second,
        ));
    }
}
