<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hierarchy. One row per node — an NFL season, a week within it, a cycling
 * stage, a TV series.
 *
 * Storage is a **materialised path** (`/1/14/37/`) rather than nested sets.
 * Taxonomy trees are tiny (hundreds of nodes) and read-heavy, so nested sets'
 * write cost buys nothing, and XenForo's own lft/rgt + depth + parent_node_id +
 * cached breadcrumb_data was four redundant representations of one tree.
 * Adjacency plus a recursive CTE is the other candidate, but CTEs make the
 * four-engine guarantee fiddly for no gain at this size.
 *
 * A term is scoped to its content type, which is what lets `Week` on NFL Game
 * and `Week` on a TV type coexist without colliding.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('taxonomy_terms')) {
            return;
        }

        Schema::create('taxonomy_terms', function (Blueprint $table) {
            $table->id();

            // Scoping. Levels are owned by a content type — this is the
            // structural difference from facets, which are shared.
            $table->string('content_type', 64);
            $table->string('level', 64);

            $table->string('value');
            $table->string('label')->nullable();

            // A parent going away must not take its children with it: the
            // destructive-edit policy says classification data is never
            // deleted by a definition edit, it becomes unreferenced and
            // recoverable. Enforced here rather than trusted to the app layer.
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('taxonomy_terms')
                ->nullOnDelete();

            // The same value as parent_id, with 0 standing in for "root".
            //
            // This exists because SQL treats NULLs as distinct in a unique
            // index: with parent_id alone, two root-level "2006" seasons in
            // one content type both insert happily, and the duplicate-naming
            // failure this whole Spec exists to prevent walks straight back
            // in. Verified on sqlite, mysql and pgsql — standard behaviour,
            // not an engine quirk.
            //
            // The portable fixes are a sentinel or a partial index. Postgres
            // 15+ has NULLS NOT DISTINCT and MySQL has nothing equivalent, so
            // a sentinel is the only answer that holds on all four engines.
            // parent_id stays nullable because the FK needs nullOnDelete to
            // leave orphans recoverable; the two columns are kept in step by
            // the model (CP3), which is the tradeoff this buys.
            $table->unsignedBigInteger('parent_key')->default(0);

            // Materialised path of ancestor ids, slash-delimited: `/1/14/37/`.
            //
            // 255 rather than a longer column deliberately. MySQL and MariaDB
            // cap a utf8mb4 index entry at 3072 bytes (768 characters), so
            // 255 is far inside the limit while still holding a ~40-deep tree
            // of six-digit ids — an order of magnitude past anything a real
            // taxonomy reaches.
            $table->string('path', 255)->default('/');

            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            // Prefix queries: everything under /1/14/.
            $table->index('path');

            // The cross-cutting query, and the reason this Spec exists —
            // "all week 12 games" across every season and every league, which
            // the legacy tracker could not express at all.
            $table->index(['content_type', 'level', 'value'], 'taxonomy_terms_lookup_index');

            // Two "Week 12" rows under one parent in one content type is a
            // duplicate, not a second thing. Scoped by parent, so week 12 of
            // 2006 and week 12 of 2007 stay separate.
            //
            // Keyed on parent_key rather than parent_id — see above for why
            // the nullable column cannot carry this.
            //
            // Named explicitly: the generated name would exceed MySQL's
            // 64-character identifier limit.
            $table->unique(['content_type', 'level', 'parent_key', 'value'], 'taxonomy_terms_unique_sibling');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_terms');
    }
};
