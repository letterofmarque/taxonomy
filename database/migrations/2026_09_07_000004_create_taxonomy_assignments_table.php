<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facet values on a torrent. Many-to-many, deliberately.
 *
 * Resolved open question 1, settled by Dan's counter-example: "netflix rips
 * these days will often have half a dozen or even more audio tracks and even
 * more subtitle tracks." So `resolution` holding one value and `subtitles`
 * holding twelve is a property of the *vocabulary*, not the schema. A
 * definition may declare a facet single-valued as a constraint; the storage
 * does not care either way, which is the permissive choice and forecloses
 * nothing.
 *
 * What this deliberately is NOT: ordered per-track metadata. A facet answers
 * "which values from this closed vocabulary apply" — a set, unordered. Nobody
 * filters a catalogue on "the third subtitle track". Per-track detail is
 * structured file metadata and belongs in its own Spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('taxonomy_assignments')) {
            return;
        }

        Schema::create('taxonomy_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('torrent_id')
                ->constrained('torrents')
                ->cascadeOnDelete();

            // A facet value going away takes its assignments with it. Unlike a
            // term, a facet value is vocabulary rather than user
            // classification — the destructive-edit command is what protects
            // an admin from dropping a vocabulary still in use, and it reports
            // the count before doing anything (CP5).
            $table->foreignId('facet_value_id')
                ->constrained('taxonomy_facet_values')
                ->cascadeOnDelete();

            $table->timestamps();

            // Filtering on any one value must find the torrent.
            $table->index('facet_value_id');

            // The same value twice on one torrent is a duplicate, not a second
            // subtitle track.
            $table->unique(['torrent_id', 'facet_value_id'], 'taxonomy_assignments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_assignments');
    }
};
