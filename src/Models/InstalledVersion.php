<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The version of a content type this tracker is running.
 *
 * @property int $id
 * @property string $content_type
 * @property int $version
 */
class InstalledVersion extends Model
{
    protected $table = 'taxonomy_installed_versions';

    protected $fillable = ['content_type', 'version'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public static function for(string $contentType): ?self
    {
        return static::where('content_type', $contentType)->first();
    }

    /**
     * Record the version in force, but only the first time.
     *
     * Deliberately never updates an existing row: that is what
     * `Upgrader::apply()` does, deliberately and with confirmation. If merely
     * loading a newer definition could move this number, `composer update`
     * would silently adopt a new shape — the exact thing the mechanism exists
     * to prevent.
     */
    public static function remember(string $contentType, int $version): void
    {
        static::firstOrCreate(
            ['content_type' => $contentType],
            ['version' => $version],
        );
    }
}
