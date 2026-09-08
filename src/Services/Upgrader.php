<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Services;

use Illuminate\Support\Facades\DB;
use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Definitions\Migration;
use Marque\Taxonomy\Exceptions\UpgradeRefusedException;
use Marque\Taxonomy\Models\Classification;
use Marque\Taxonomy\Models\InstalledVersion;
use Marque\Taxonomy\Models\Term;

/**
 * Applies a definition change to a live catalogue — deliberately, never
 * silently.
 *
 * Dan's framing governs the whole class:
 *
 * > "this isn't an easy on them hard on us thing, this is protecting some
 * > people from themselves, we need to make it harder for them to fuck things
 * > up like that."
 *
 * Two entry points for two situations that feel identical from the admin's
 * side: `apply()` for a package author's version bump, `applyDestructive()`
 * for the admin's own edit. Both refuse to run without the caller having seen
 * what it costs.
 *
 * **Nothing here ever deletes a classification.** Terms go; the rows pointing
 * at them are nulled by the CP2 foreign keys and survive, carrying their
 * content_type so an orphan still says what it was.
 */
class Upgrader
{
    /**
     * Content types whose shipped version is ahead of what this tracker runs.
     *
     * @param  list<ContentType>  $shipped
     * @return list<array{content_type: string, from: int, to: int, affected: int, path: bool}>
     */
    public function pending(array $shipped): array
    {
        $pending = [];

        foreach ($shipped as $type) {
            $installed = InstalledVersion::for($type->name);

            // Nothing classified under it yet, so there is nothing to protect
            // and adopting the new version costs nothing.
            if ($installed === null || $installed->version >= $type->version) {
                continue;
            }

            $pending[] = [
                'content_type' => $type->name,
                'from' => $installed->version,
                'to' => $type->version,
                'affected' => Classification::where('content_type', $type->name)->count(),
                'path' => $type->migrationPathFrom($installed->version) !== null,
            ];
        }

        return $pending;
    }

    /**
     * Walk a content type from its installed version up to the shipped one.
     *
     * Every step runs inside one transaction: a half-applied upgrade is
     * precisely the undiagnosable state this design exists to prevent, so a
     * step failing partway leaves the catalogue exactly as it was.
     */
    public function apply(ContentType $shipped): void
    {
        $installed = InstalledVersion::for($shipped->name);
        $from = $installed?->version ?? $shipped->version;

        if ($from >= $shipped->version) {
            return;
        }

        $path = $shipped->migrationPathFrom($from);

        if ($path === null) {
            throw UpgradeRefusedException::noPath($shipped->name, $from, $shipped->version);
        }

        DB::transaction(function () use ($shipped, $path): void {
            foreach ($path as $step) {
                $this->applyStep($shipped->name, $step);
            }

            InstalledVersion::updateOrCreate(
                ['content_type' => $shipped->name],
                ['version' => $shipped->version],
            );
        });
    }

    /**
     * Apply an admin's own destructive edit, after they have seen the cost.
     *
     * The Drift argument is not decoration: requiring it means a caller cannot
     * reach this method without having computed what it would orphan, which is
     * the protective posture expressed in the signature rather than in a
     * comment.
     */
    public function applyDestructive(ContentType $edited, Drift $drift): void
    {
        DB::transaction(function () use ($edited, $drift): void {
            if ($drift->removedLevels !== []) {
                // Deleting the terms orphans the classifications pointing at
                // them — nullOnDelete from CP2. The rows survive, which is the
                // whole property: "data is never deleted by a definition edit,
                // it becomes unreferenced and recoverable."
                Term::query()
                    ->where('content_type', $edited->name)
                    ->whereIn('level', $drift->removedLevels)
                    ->delete();
            }

            // Facet vocabularies are deliberately left alone. They are shared
            // across content types, so dropping `subtitles` from one
            // definition must not delete the vocabulary out from under every
            // other type still using it. The assignments stay too — they
            // describe torrents, and this type no longer having an opinion
            // about them is not a reason to discard them.
        });
    }

    private function applyStep(string $contentType, Migration $step): void
    {
        foreach ($step->renamedLevels as $rename) {
            $renamed = Term::query()
                ->where('content_type', $contentType)
                ->where('level', $rename['from'])
                ->update(['level' => $rename['to']]);

            // A rename that matches nothing is a declaration error worth
            // stopping for: the author believed a level existed. Silently
            // doing nothing would leave the catalogue in a shape neither the
            // old nor the new definition describes.
            if ($renamed === 0 && Term::where('content_type', $contentType)->exists()) {
                throw UpgradeRefusedException::unknownLevel($contentType, $rename['from'], 'rename');
            }
        }

        foreach ($step->removedLevels as $level) {
            $exists = Term::query()
                ->where('content_type', $contentType)
                ->where('level', $level)
                ->exists();

            if (! $exists) {
                throw UpgradeRefusedException::unknownLevel($contentType, $level, 'remove');
            }

            Term::query()
                ->where('content_type', $contentType)
                ->where('level', $level)
                ->delete();
        }

        // add_level, add_facet and remove_facet need no data change. A new
        // level becomes classifiable from the next upload; existing torrents
        // simply have not got one, which is what `default: null` means. A
        // removed facet stops being offered on the upload form and its
        // existing assignments stay, because they still describe the torrent.
    }
}
