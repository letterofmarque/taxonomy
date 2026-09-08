<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables the host app owns, stood up for tests.
 *
 * Taxonomy ships no users table — users belong to the host (via
 * trove.user_model). Trove's own migrations add columns to it, so it has to
 * exist before they run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('user');
            $table->string('status')->default('active');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Package migrations register before this fixture (providers call
        // loadMigrationsFrom in boot), so rollback reverses that order and
        // reaches `users` while tables referencing it still exist. SQLite does
        // not enforce foreign keys by default and never noticed; MySQL and
        // PostgreSQL both refuse.
        //
        // Postgres ignores disableForeignKeyConstraints for DROP TABLE, so the
        // portable fix is to take the dependants down first, deepest first.
        //
        // The taxonomy tables are listed here as well as in their own
        // migrations' down(): this fixture is what tears the environment down
        // between test FILES, and by then `torrents` is about to go while
        // classifications and assignments still point at it. Copying parley's
        // fixture verbatim missed that, and MySQL failed the whole suite while
        // passing every file in isolation.
        Schema::dropIfExists('taxonomy_installed_versions');
        Schema::dropIfExists('taxonomy_assignments');
        Schema::dropIfExists('taxonomy_classifications');
        Schema::dropIfExists('taxonomy_facet_values');
        Schema::dropIfExists('taxonomy_facets');
        Schema::dropIfExists('taxonomy_terms');
        Schema::dropIfExists('torrents');
        Schema::dropIfExists('users');
    }
};
