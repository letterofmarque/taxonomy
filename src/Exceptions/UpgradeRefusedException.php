<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Exceptions;

use RuntimeException;

/**
 * An upgrade could not be applied, so nothing was applied.
 *
 * The refusal is deliberate and is the mechanism, not an inconvenience around
 * it: a version bump carrying no migration path means the package author never
 * said what their change does to existing data, and the admin is the wrong
 * person to guess. Better to stop than to reshape a live catalogue on a
 * guess.
 */
final class UpgradeRefusedException extends RuntimeException
{
    public static function noPath(string $contentType, int $from, int $to): self
    {
        return new self(sprintf(
            'Cannot upgrade "%s" from v%d to v%d: no migration path is declared for every step between them. '
            .'The package author has to say what the change does to existing classifications — '
            .'that is not something to infer.',
            $contentType,
            $from,
            $to,
        ));
    }

    public static function unknownLevel(string $contentType, string $level, string $operation): self
    {
        return new self(sprintf(
            'Migration for "%s" tries to %s level "%s", which does not exist. Nothing was applied.',
            $contentType,
            $operation,
            $level,
        ));
    }
}
