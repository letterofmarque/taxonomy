<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where one torrent sits in one content type's hierarchy.
 *
 * @property int $id
 * @property int $torrent_id
 * @property int|null $term_id
 * @property string $content_type
 * @property string|null $grouping_key
 */
class Classification extends Model
{
    protected $table = 'taxonomy_classifications';

    protected $fillable = [
        'torrent_id',
        'term_id',
        'content_type',
        'grouping_key',
    ];

    protected function casts(): array
    {
        return [
            'torrent_id' => 'integer',
            'term_id' => 'integer',
        ];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'term_id');
    }

    /**
     * Orphaned by a destructive definition edit — the term it pointed at is
     * gone, but the classification survives and says what it used to be.
     */
    public function isOrphaned(): bool
    {
        return $this->term_id === null;
    }
}
