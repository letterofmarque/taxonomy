<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Exceptions;

use RuntimeException;

/**
 * A torrent was classified in a way its content type does not allow.
 *
 * Thrown rather than silently coerced or stored. A definition's declared range
 * is a constraint, not a comment — if week 47 can be stored on a content type
 * declaring `range: [1, 22]`, then the definition is documentation and the
 * catalogue drifts exactly the way the legacy tracker's did.
 */
final class InvalidClassificationException extends RuntimeException
{
    public static function unknownLevel(string $level, string $contentType): self
    {
        return new self(sprintf('Content type "%s" declares no level "%s".', $contentType, $level));
    }

    public static function unknownFacet(string $facet, string $contentType): self
    {
        return new self(sprintf('Content type "%s" declares no facet "%s".', $contentType, $facet));
    }

    public static function rejectedValue(string $level, string $value): self
    {
        return new self(sprintf('Level "%s" does not accept the value "%s".', $level, $value));
    }

    public static function gap(string $missing, string $present): self
    {
        return new self(sprintf(
            'Cannot classify at "%s" without "%s": a hierarchy path may not skip a level. '
            .'There is no "%s" independent of which "%s" it belongs to.',
            $present,
            $missing,
            $present,
            $missing,
        ));
    }

    public static function empty(string $contentType): self
    {
        return new self(sprintf('Classifying under "%s" requires at least the first level.', $contentType));
    }
}
