<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facet vocabularies, and the values in them.
 *
 * Facets are flat closed vocabularies **shared across content types** —
 * `resolution` is `resolution` everywhere, which is exactly what makes them
 * different from levels. There is deliberately no content_type column here.
 *
 * Vocabularies are still per-tracker: an HD-only tracker drops 480p from
 * `resolution` the same way a US-only tracker drops CFL from its leagues.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('taxonomy_facets')) {
            Schema::create('taxonomy_facets', function (Blueprint $table) {
                $table->id();

                $table->string('name', 64)->unique();
                $table->string('label');
                $table->unsignedInteger('position')->default(0);

                $table->timestamps();
            });
        }

        if (! Schema::hasTable('taxonomy_facet_values')) {
            Schema::create('taxonomy_facet_values', function (Blueprint $table) {
                $table->id();

                // A value is meaningless without its vocabulary, so this one
                // genuinely cascades — unlike a classification, which is user
                // data and must survive.
                $table->foreignId('facet_id')
                    ->constrained('taxonomy_facets')
                    ->cascadeOnDelete();

                $table->string('value');
                $table->string('label');
                $table->unsignedInteger('position')->default(0);

                $table->timestamps();

                // `web` may mean a resolution source and a codec source in two
                // vocabularies; it may not appear twice in one.
                $table->unique(['facet_id', 'value'], 'taxonomy_facet_values_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_facet_values');
        Schema::dropIfExists('taxonomy_facets');
    }
};
