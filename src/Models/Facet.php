<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A shared facet vocabulary — resolution, source, subtitles.
 *
 * @property int $id
 * @property string $name
 * @property string $label
 * @property int $position
 */
class Facet extends Model
{
    protected $table = 'taxonomy_facets';

    protected $fillable = ['name', 'label', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function values(): HasMany
    {
        return $this->hasMany(FacetValue::class, 'facet_id');
    }
}
