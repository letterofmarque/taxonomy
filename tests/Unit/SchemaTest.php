<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CP2's schema is the layer that cannot change later without a real migration —
 * everything above it is runtime and cheap to revise. So the constraints are
 * proven here rather than assumed, on every engine the suite runs against.
 *
 * DB-agnostic by rule (docs/why.md): no raw SQL, no engine-specific types.
 */
describe('migrations', function () {
    it('creates every table', function (string $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    })->with([
        'taxonomy_terms',
        'taxonomy_facets',
        'taxonomy_facet_values',
        'taxonomy_assignments',
        'taxonomy_classifications',
    ]);

    it('prefixes tables so they cannot collide with a host app', function () {
        // A host app is entirely likely to have its own "terms" or "facets".
        expect(Schema::hasTable('terms'))->toBeFalse()
            ->and(Schema::hasTable('facets'))->toBeFalse()
            ->and(Schema::hasTable('assignments'))->toBeFalse()
            ->and(Schema::hasTable('classifications'))->toBeFalse();
    });
});

describe('taxonomy_terms — the hierarchy', function () {
    it('stores a materialised path rather than nested set bounds', function () {
        // Spec #103: materialised path, not nested sets. Taxonomy trees are
        // tiny and read-heavy, so nested sets' write cost buys nothing, and
        // recursive CTEs make the four-engine guarantee fiddly.
        expect(Schema::hasColumns('taxonomy_terms', ['content_type', 'level', 'value', 'path']))->toBeTrue()
            ->and(Schema::hasColumn('taxonomy_terms', 'lft'))->toBeFalse()
            ->and(Schema::hasColumn('taxonomy_terms', 'rgt'))->toBeFalse();
    });

    it('scopes a term to its content type, so two types may share a level name', function () {
        // The acceptance criterion (Spec #103 #19): `Week` on NFL Game and
        // `Week` on a TV type are independent. Both rows insert without a
        // uniqueness collision.
        $nfl = DB::table('taxonomy_terms')->insertGetId([
            'content_type' => 'nfl_game',
            'level' => 'week',
            'value' => '12',
            'path' => '/',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tv = DB::table('taxonomy_terms')->insertGetId([
            'content_type' => 'tv_episode',
            'level' => 'week',
            'value' => '12',
            'path' => '/',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect($nfl)->not->toBe($tv)
            ->and(DB::table('taxonomy_terms')->where('level', 'week')->count())->toBe(2);
    });

    it('rejects the same value twice at the root of one content type', function () {
        // The case a naive unique index misses. SQL treats NULLs as distinct,
        // so keying uniqueness on a nullable parent_id lets two root-level
        // "2006" seasons both insert — which is the duplicate-naming failure
        // this Spec exists to prevent. Hence the parent_key sentinel column.
        //
        // Confirmed on sqlite, mysql and pgsql before the fix: all three
        // accepted the duplicate.
        DB::table('taxonomy_terms')->insert([
            'content_type' => 'nfl_game',
            'level' => 'season',
            'value' => '2006',
            'path' => '/',
            'parent_id' => null,
            'parent_key' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::beginTransaction();

        try {
            expect(fn () => DB::table('taxonomy_terms')->insert([
                'content_type' => 'nfl_game',
                'level' => 'season',
                'value' => '2006',
                'path' => '/',
                'parent_id' => null,
                'parent_key' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]))->toThrow(QueryException::class);
        } finally {
            DB::rollBack();
        }
    });

    it('rejects the same value twice under one parent', function () {
        $season = DB::table('taxonomy_terms')->insertGetId([
            'content_type' => 'nfl_game', 'level' => 'season', 'value' => '2006',
            'path' => '/', 'parent_key' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('taxonomy_terms')->insert([
            'content_type' => 'nfl_game',
            'level' => 'week',
            'value' => '12',
            'path' => '/'.$season.'/',
            'parent_id' => $season,
            'parent_key' => $season,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::beginTransaction();

        try {
            expect(fn () => DB::table('taxonomy_terms')->insert([
                'content_type' => 'nfl_game',
                'level' => 'week',
                'value' => '12',
                'path' => '/'.$season.'/',
                'parent_id' => $season,
                'parent_key' => $season,
                'created_at' => now(),
                'updated_at' => now(),
            ]))->toThrow(QueryException::class);
        } finally {
            DB::rollBack();
        }
    });

    it('keeps the same value separate under different parents', function () {
        // Week 12 of 2006 and week 12 of 2007 are different things. The
        // uniqueness must not collapse them.
        $a = DB::table('taxonomy_terms')->insertGetId([
            'content_type' => 'nfl_game', 'level' => 'season', 'value' => '2006',
            'path' => '/', 'parent_key' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $b = DB::table('taxonomy_terms')->insertGetId([
            'content_type' => 'nfl_game', 'level' => 'season', 'value' => '2007',
            'path' => '/', 'parent_key' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([$a, $b] as $season) {
            DB::table('taxonomy_terms')->insert([
                'content_type' => 'nfl_game', 'level' => 'week', 'value' => '12',
                'path' => '/'.$season.'/', 'parent_id' => $season, 'parent_key' => $season,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        expect(DB::table('taxonomy_terms')->where('level', 'week')->count())->toBe(2);
    });

    it('lets a term point at its parent and survives that parent going away', function () {
        // A parent term is not allowed to take its children down with it —
        // the destructive-edit policy (Spec #103 #20) says classification data
        // is never deleted by a definition edit, it becomes unreferenced and
        // recoverable. Enforced structurally here rather than trusted to the
        // application layer.
        $season = DB::table('taxonomy_terms')->insertGetId([
            'content_type' => 'nfl_game',
            'level' => 'season',
            'value' => '2006',
            'path' => '/',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_terms')->insert([
            'content_type' => 'nfl_game',
            'level' => 'week',
            'value' => '12',
            'path' => '/'.$season.'/',
            'parent_id' => $season,
            'parent_key' => $season,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_terms')->where('id', $season)->delete();

        $orphan = DB::table('taxonomy_terms')->where('value', '12')->first();

        expect($orphan)->not->toBeNull()
            ->and($orphan->parent_id)->toBeNull()
            // parent_key deliberately keeps the dead parent's id rather than
            // resetting to 0. An orphan is not a root term, and treating it as
            // one would let it collide with a real root. Repair is CP5's job,
            // where the admin is told what was orphaned.
            ->and((int) $orphan->parent_key)->toBe($season);
    });

    it('rejects a parent_id that points at nothing', function () {
        DB::beginTransaction();

        try {
            expect(fn () => DB::table('taxonomy_terms')->insert([
                'content_type' => 'nfl_game',
                'level' => 'week',
                'value' => '12',
                'path' => '/99999/',
                'parent_id' => 99999,
                'created_at' => now(),
                'updated_at' => now(),
            ]))->toThrow(QueryException::class);
        } finally {
            DB::rollBack();
        }
    });
});

describe('taxonomy_facets — shared closed vocabularies', function () {
    it('does not scope a facet to a content type', function () {
        // Spec #103: facet vocabularies are shared across content types —
        // `resolution` is `resolution` everywhere. This is the structural
        // difference from levels, which are owned by one content type.
        expect(Schema::hasColumn('taxonomy_facets', 'content_type'))->toBeFalse();
    });

    it('rejects two facets with the same name', function () {
        DB::table('taxonomy_facets')->insert([
            'name' => 'resolution',
            'label' => 'Resolution',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::beginTransaction();

        try {
            expect(fn () => DB::table('taxonomy_facets')->insert([
                'name' => 'resolution',
                'label' => 'Resolution again',
                'created_at' => now(),
                'updated_at' => now(),
            ]))->toThrow(QueryException::class);
        } finally {
            DB::rollBack();
        }
    });

    it('takes its values away with it when a facet is dropped', function () {
        // A facet's values are meaningless without the facet, unlike a
        // classification, which is user data. So this one genuinely cascades.
        $facet = DB::table('taxonomy_facets')->insertGetId([
            'name' => 'resolution',
            'label' => 'Resolution',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_facet_values')->insert([
            'facet_id' => $facet,
            'value' => '1080p',
            'label' => '1080p',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('taxonomy_facets')->where('id', $facet)->delete();

        expect(DB::table('taxonomy_facet_values')->count())->toBe(0);
    });

    it('rejects the same value twice within one facet but allows it across facets', function () {
        $resolution = DB::table('taxonomy_facets')->insertGetId([
            'name' => 'resolution', 'label' => 'Resolution',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $source = DB::table('taxonomy_facets')->insertGetId([
            'name' => 'source', 'label' => 'Source',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('taxonomy_facet_values')->insert([
            'facet_id' => $resolution, 'value' => 'web', 'label' => 'Web',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Same string, different vocabulary — legitimate, must be allowed.
        DB::table('taxonomy_facet_values')->insert([
            'facet_id' => $source, 'value' => 'web', 'label' => 'WEB-DL',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        expect(DB::table('taxonomy_facet_values')->count())->toBe(2);

        DB::beginTransaction();

        try {
            expect(fn () => DB::table('taxonomy_facet_values')->insert([
                'facet_id' => $resolution, 'value' => 'web', 'label' => 'duplicate',
                'created_at' => now(), 'updated_at' => now(),
            ]))->toThrow(QueryException::class);
        } finally {
            DB::rollBack();
        }
    });
});

describe('taxonomy_assignments — facet values on a torrent', function () {
    it('lets one torrent carry many values of the same facet', function () {
        // Resolved open question 1, from Dan's counter-example: "netflix rips
        // these days will often have half a dozen or even more audio tracks
        // and even more subtitle tracks." Many-to-many is the permissive
        // choice; single-valued is a definition-declared constraint, not a
        // schema one.
        $user = DB::table('users')->insertGetId([
            'name' => 'u', 'email' => 'u@example.com', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $torrent = DB::table('torrents')->insertGetId([
            'info_hash' => str_repeat('a', 40), 'name' => 't', 'user_id' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $subtitles = DB::table('taxonomy_facets')->insertGetId([
            'name' => 'subtitles', 'label' => 'Subtitles',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['en', 'es', 'fr', 'de', 'ja', 'ko', 'pt', 'it', 'nl', 'pl', 'sv', 'da'] as $lang) {
            $value = DB::table('taxonomy_facet_values')->insertGetId([
                'facet_id' => $subtitles, 'value' => $lang, 'label' => $lang,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('taxonomy_assignments')->insert([
                'torrent_id' => $torrent,
                'facet_value_id' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        expect(DB::table('taxonomy_assignments')->where('torrent_id', $torrent)->count())->toBe(12);
    });

    it('rejects the same facet value assigned twice to one torrent', function () {
        $user = DB::table('users')->insertGetId([
            'name' => 'u', 'email' => 'u2@example.com', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $torrent = DB::table('torrents')->insertGetId([
            'info_hash' => str_repeat('b', 40), 'name' => 't', 'user_id' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $facet = DB::table('taxonomy_facets')->insertGetId([
            'name' => 'resolution', 'label' => 'Resolution',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $value = DB::table('taxonomy_facet_values')->insertGetId([
            'facet_id' => $facet, 'value' => '1080p', 'label' => '1080p',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('taxonomy_assignments')->insert([
            'torrent_id' => $torrent, 'facet_value_id' => $value,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::beginTransaction();

        try {
            expect(fn () => DB::table('taxonomy_assignments')->insert([
                'torrent_id' => $torrent, 'facet_value_id' => $value,
                'created_at' => now(), 'updated_at' => now(),
            ]))->toThrow(QueryException::class);
        } finally {
            DB::rollBack();
        }
    });

    it('clears assignments when the torrent goes, since they describe it', function () {
        $user = DB::table('users')->insertGetId([
            'name' => 'u', 'email' => 'u3@example.com', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $torrent = DB::table('torrents')->insertGetId([
            'info_hash' => str_repeat('c', 40), 'name' => 't', 'user_id' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $facet = DB::table('taxonomy_facets')->insertGetId([
            'name' => 'resolution', 'label' => 'Resolution',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $value = DB::table('taxonomy_facet_values')->insertGetId([
            'facet_id' => $facet, 'value' => '1080p', 'label' => '1080p',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('taxonomy_assignments')->insert([
            'torrent_id' => $torrent, 'facet_value_id' => $value,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('torrents')->where('id', $torrent)->delete();

        expect(DB::table('taxonomy_assignments')->count())->toBe(0);
    });
});

describe('taxonomy_classifications — where a torrent sits, and what it groups with', function () {
    it('carries a nullable grouping key core never interprets', function () {
        // Spec #103 #23: the key ships in v1 precisely because retrofitting it
        // means backfilling live data. A content type that finds it meaningless
        // leaves it null and nothing degrades.
        expect(Schema::hasColumn('taxonomy_classifications', 'grouping_key'))->toBeTrue();

        $user = DB::table('users')->insertGetId([
            'name' => 'u', 'email' => 'u4@example.com', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $torrent = DB::table('torrents')->insertGetId([
            'info_hash' => str_repeat('d', 40), 'name' => 't', 'user_id' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $term = DB::table('taxonomy_terms')->insertGetId([
            'content_type' => 'nfl_game', 'level' => 'week', 'value' => '12', 'path' => '/',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('taxonomy_classifications')->insert([
            'torrent_id' => $torrent,
            'term_id' => $term,
            'content_type' => 'nfl_game',
            'grouping_key' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect(DB::table('taxonomy_classifications')->whereNull('grouping_key')->count())->toBe(1);
    });

    it('groups several torrents under one key', function () {
        // Four encodes of one game. Core groups; it does not model what a
        // "thing" is.
        $user = DB::table('users')->insertGetId([
            'name' => 'u', 'email' => 'u5@example.com', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $term = DB::table('taxonomy_terms')->insertGetId([
            'content_type' => 'nfl_game', 'level' => 'week', 'value' => '12', 'path' => '/',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['e', 'f', 'g', 'h'] as $i => $char) {
            $torrent = DB::table('torrents')->insertGetId([
                'info_hash' => str_repeat($char, 40), 'name' => 'encode '.$i, 'user_id' => $user,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('taxonomy_classifications')->insert([
                'torrent_id' => $torrent,
                'term_id' => $term,
                'content_type' => 'nfl_game',
                'grouping_key' => 'nfl:2006:12:phi-dal',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        expect(DB::table('taxonomy_classifications')->where('grouping_key', 'nfl:2006:12:phi-dal')->count())->toBe(4);
    });

    it('does not take torrents down with a term', function () {
        // The FK decision CP2 calls out explicitly: "a term with torrents
        // attached must not cascade them away." Deleting a term must never
        // delete a torrent — the classification is what becomes unreferenced.
        $user = DB::table('users')->insertGetId([
            'name' => 'u', 'email' => 'u6@example.com', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $torrent = DB::table('torrents')->insertGetId([
            'info_hash' => str_repeat('i', 40), 'name' => 't', 'user_id' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $term = DB::table('taxonomy_terms')->insertGetId([
            'content_type' => 'nfl_game', 'level' => 'week', 'value' => '12', 'path' => '/',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('taxonomy_classifications')->insert([
            'torrent_id' => $torrent, 'term_id' => $term, 'content_type' => 'nfl_game',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('taxonomy_terms')->where('id', $term)->delete();

        expect(DB::table('torrents')->where('id', $torrent)->count())->toBe(1)
            ->and(DB::table('taxonomy_classifications')->whereNull('term_id')->count())->toBe(1);
    });

    it('clears a classification when its torrent goes', function () {
        $user = DB::table('users')->insertGetId([
            'name' => 'u', 'email' => 'u7@example.com', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $torrent = DB::table('torrents')->insertGetId([
            'info_hash' => str_repeat('j', 40), 'name' => 't', 'user_id' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('taxonomy_classifications')->insert([
            'torrent_id' => $torrent, 'term_id' => null, 'content_type' => 'nfl_game',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('torrents')->where('id', $torrent)->delete();

        expect(DB::table('taxonomy_classifications')->count())->toBe(0);
    });
});

describe('index coverage — the queries CP4 depends on', function () {
    it('indexes the path for prefix queries and level+value for cross-cutting ones', function () {
        // "all week 12 games across every season" is the second index, and it
        // is the specific failure the legacy tracker had (Spec #103 Purpose).
        // Asserted structurally rather than by EXPLAIN, which differs per
        // engine — this runs identically on all four.
        $indexes = collect(Schema::getIndexes('taxonomy_terms'))
            ->map(fn (array $i): array => $i['columns'])
            ->all();

        expect($indexes)->toContain(['path'])
            ->and($indexes)->toContain(['content_type', 'level', 'value']);
    });

    it('indexes the grouping key, since finding siblings is its only purpose', function () {
        $indexes = collect(Schema::getIndexes('taxonomy_classifications'))
            ->map(fn (array $i): array => $i['columns'])
            ->all();

        expect($indexes)->toContain(['grouping_key']);
    });
});
