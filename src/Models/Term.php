<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One node in a content type's hierarchy.
 *
 * @property int $id
 * @property string $content_type
 * @property string $level
 * @property string $value
 * @property string|null $label
 * @property int|null $parent_id
 * @property int $parent_key
 * @property string $path
 * @property int $position
 */
class Term extends Model
{
    protected $table = 'taxonomy_terms';

    // Explicit rather than $guarded = [] — a package cannot assume its
    // consumers have called Model::unguard() (CONTRIBUTING.md).
    protected $fillable = [
        'content_type',
        'level',
        'value',
        'label',
        'parent_id',
        'parent_key',
        'path',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'parent_key' => 'integer',
            'position' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // parent_key exists because SQL treats NULLs as distinct in a unique
        // index, so the sibling-uniqueness constraint cannot be keyed on the
        // nullable parent_id (see the CP2 migration). Keeping the two in step
        // is this model's job, and doing it here rather than at every call
        // site is what stops them drifting.
        //
        // Deliberately only on create: a term whose parent is deleted keeps
        // its old parent_key, because an orphan is not a root term and must
        // not be able to collide with one.
        static::creating(function (self $term): void {
            $term->parent_key ??= $term->parent_id ?? 0;
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * The path a child of this term would carry.
     *
     * Materialised path of ancestor ids: a root term sits at `/`, and its
     * children at `/<root id>/`.
     */
    public function pathForChildren(): string
    {
        return $this->path.$this->id.'/';
    }

    /**
     * Every term beneath this one, at any depth.
     *
     * A prefix match on the indexed path column — the reason materialised path
     * was chosen over nested sets for a tree this small and this read-heavy.
     */
    public function scopeDescendantsOf($query, self $term)
    {
        return $query->where('path', 'like', $term->pathForChildren().'%');
    }
}
