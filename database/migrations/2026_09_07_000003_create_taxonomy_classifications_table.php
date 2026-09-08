<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a torrent sits in the hierarchy, and what it groups with.
 *
 * A separate table rather than columns on `torrents`: trove owns that table,
 * taxonomy is optional, and an install that never classifies anything should
 * carry no taxonomy columns at all.
 *
 * The two foreign keys behave deliberately differently, and the asymmetry is
 * the whole point:
 *
 *   torrent gone  → the classification goes with it. It described that torrent.
 *   term gone     → the classification SURVIVES, orphaned and recoverable.
 *
 * The second is the destructive-edit policy enforced structurally (Spec #103):
 * "a term with torrents attached must not cascade them away", and "data is
 * never deleted by a definition edit — it becomes unreferenced and
 * recoverable."
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('taxonomy_classifications')) {
            return;
        }

        Schema::create('taxonomy_classifications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('torrent_id')
                ->constrained('torrents')
                ->cascadeOnDelete();

            // Nullable, and nullOnDelete: an orphaned classification is the
            // recoverable state a destructive definition edit leaves behind.
            $table->foreignId('term_id')
                ->nullable()
                ->constrained('taxonomy_terms')
                ->nullOnDelete();

            // Kept alongside term_id so an orphaned row still says what it was
            // classified as. Without it, nulling term_id would lose that too.
            $table->string('content_type', 64);

            // "These rows are the same underlying thing" — four encodes of one
            // game. Core stores it and never interprets it: no Work model, no
            // entity table. A content type that finds it meaningless leaves it
            // null and nothing degrades.
            //
            // Shipped now rather than deferred because retrofitting a column
            // onto a live catalogue means a backfill.
            $table->string('grouping_key')->nullable();

            $table->timestamps();

            // Finding a torrent's siblings is the key's only purpose.
            $table->index('grouping_key');

            $table->index(['content_type', 'term_id'], 'taxonomy_classifications_lookup_index');

            // One classification per torrent per content type. A torrent may
            // legitimately be classified under two content types; it may not
            // sit at two places in one.
            $table->unique(['torrent_id', 'content_type'], 'taxonomy_classifications_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_classifications');
    }
};
