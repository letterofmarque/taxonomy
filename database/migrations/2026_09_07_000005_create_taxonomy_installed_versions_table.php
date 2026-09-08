<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which version of each content type this tracker is actually running.
 *
 * Without it, "has a package author changed the definition underneath me?" is
 * unanswerable — the shipped file says v2 and there is nothing to compare it
 * against. That question is the whole basis of the upgrade protection, so the
 * answer has to be durable rather than inferred.
 *
 * Written when a content type is first used to classify something, not when a
 * definition file appears. A definition nobody has classified under carries no
 * risk and needs no ceremony to adopt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('taxonomy_installed_versions')) {
            return;
        }

        Schema::create('taxonomy_installed_versions', function (Blueprint $table) {
            $table->id();

            $table->string('content_type', 64)->unique();
            $table->unsignedInteger('version');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_installed_versions');
    }
};
