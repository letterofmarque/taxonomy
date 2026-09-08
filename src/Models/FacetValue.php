<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One value in a facet vocabulary — `1080p` in `resolution`.
 *
 * @property int $id
 * @property int $facet_id
 * @property string $value
 * @property string $label
 * @property int $position
 */
class FacetValue extends Model
{
    protected $table = 'taxonomy_facet_values';

    protected $fillable = ['facet_id', 'value', 'label', 'position'];

    protected function casts(): array
    {
        return [
            'facet_id' => 'integer',
            'position' => 'integer',
        ];
    }

    public function facet(): BelongsTo
    {
        return $this->belongsTo(Facet::class, 'facet_id');
    }

    /**
     * How many torrents carry this value.
     *
     * The schema cascades assignments away when a value is deleted, which is
     * correct for a definition-driven removal that has already reported its
     * count (CP5) and wrong for a click on an admin screen. This is what lets
     * the admin surface refuse instead.
     */
    public function torrentAssignmentCount(): int
    {
        return DB::table('taxonomy_assignments')
            ->where('facet_value_id', $this->getKey())
            ->count();
    }
}
