<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Livewire\TaxonomyAdmin;
use Marque\Taxonomy\Models\Facet;
use Marque\Taxonomy\Models\FacetValue;
use Marque\Taxonomy\Models\Term;
use Marque\Taxonomy\Services\Classifier;
use Marque\Taxonomy\Tests\TestUser;
use Marque\Trove\Enums\Role;
use Marque\Trove\Models\Torrent;

/**
 * The admin surface. The Spec's goal was *"the power lives in the package, the
 * admin experience is a form"* — explicitly not a tree builder.
 *
 * The load-bearing property is a **negative** one: an admin populates declared
 * levels and cannot invent or destroy them. That separation is why the design
 * survives contact with a real tracker. The 2018 XenForo dump is what
 * hand-built trees produce (416 week-nodes at depth 2, 219 at depth 3); the
 * legacy `categories` table is what flat lists produce (72 rows, `parent`
 * unused, seasons baked into names). Neither failure is *available* to an
 * admin who can only add values.
 */
beforeEach(function () {
    config()->set('taxonomy.definitions.path', __DIR__.'/../fixtures/taxonomies');

    $this->admin = TestUser::create([
        'name' => 'a', 'email' => 'a@example.com', 'password' => 'x', 'role' => Role::Admin->value,
    ]);

    $this->user = TestUser::create([
        'name' => 'u', 'email' => 'u@example.com', 'password' => 'x', 'role' => Role::User->value,
    ]);

    $this->actingAs($this->admin);
});

describe('the admin cannot design a hierarchy — the point of the whole design', function () {
    it('exposes no way to create a level', function () {
        // Levels are declared in YAML. There is deliberately no method to add
        // one, and this asserts the absence rather than trusting the template.
        $methods = get_class_methods(TaxonomyAdmin::class);

        foreach ($methods as $method) {
            expect(strtolower($method))
                ->not->toContain('addlevel')
                ->not->toContain('createlevel')
                ->not->toContain('removelevel')
                ->not->toContain('deletelevel');
        }
    });

    it('shows the declared levels as fixed structure, not as editable rows', function () {
        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            // The levels are visible so an admin knows what to populate...
            ->assertSee('Season')
            ->assertSee('Week')
            // ...and the shape is described as coming from the definition.
            ->assertSee('defined in');
    });

    it('refuses a term at a level the content type does not declare', function () {
        // The API-level version of the same guarantee: even reaching past the
        // form must not invent a level.
        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->set('newTerm.level', 'innings')
            ->set('newTerm.value', '2')
            ->call('addTerm')
            ->assertHasErrors('newTerm.level');

        expect(Term::where('level', 'innings')->exists())->toBeFalse();
    });
});

describe('populating the values, which is what the admin IS for', function () {
    it('adds a root term', function () {
        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->set('newTerm.level', 'season')
            ->set('newTerm.value', '2006')
            ->call('addTerm')
            ->assertHasNoErrors();

        expect(Term::where('content_type', 'nfl_game')->where('value', '2006')->exists())->toBeTrue();
    });

    it('adds a child under a chosen parent', function () {
        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->set('newTerm.level', 'season')
            ->set('newTerm.value', '2006')
            ->call('addTerm');

        $season = Term::where('level', 'season')->firstOrFail();

        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->set('newTerm.level', 'week')
            ->set('newTerm.parent', $season->id)
            ->set('newTerm.value', '12')
            ->call('addTerm')
            ->assertHasNoErrors();

        $week = Term::where('level', 'week')->firstOrFail();

        expect($week->parent_id)->toBe($season->id)
            ->and($week->path)->toBe('/'.$season->id.'/');
    });

    it('enforces the declared range on a value', function () {
        // The definition is the validation here as much as at upload.
        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->set('newTerm.level', 'week')
            ->set('newTerm.value', '47')
            ->call('addTerm')
            ->assertHasErrors('newTerm.value');
    });

    it('refuses a duplicate sibling', function () {
        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->set('newTerm.level', 'season')
            ->set('newTerm.value', '2006')
            ->call('addTerm');

        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->set('newTerm.level', 'season')
            ->set('newTerm.value', '2006')
            ->call('addTerm')
            ->assertHasErrors('newTerm.value');

        expect(Term::where('value', '2006')->count())->toBe(1);
    });

    it('renames a term without moving anything classified under it', function () {
        // A label fix — "TdF" to "Tour de France" — must not orphan anything.
        $torrent = Torrent::create([
            'info_hash' => str_repeat('a', 40), 'name' => 't', 'user_id' => $this->user->id,
        ]);

        $types = app(Loader::class)->load();
        app(Classifier::class)->classify($torrent, $types['nfl_game'], ['season' => '2006', 'week' => '12']);

        $season = Term::where('level', 'season')->firstOrFail();

        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->call('renameTerm', $season->id, '2006-07')
            ->assertHasNoErrors();

        expect($season->fresh()->label)->toBe('2006-07')
            ->and($torrent->taxonomyClassifications()->first()->term_id)->not->toBeNull();
    });
});

describe('deleting a term routes to the command rather than doing it', function () {
    it('refuses to delete a term that has torrents under it', function () {
        // "Route to CP5's upgrade and destructive-edit commands rather than
        // reimplementing them in the UI. The confirmation step is deliberately
        // a command, not a button."
        $torrent = Torrent::create([
            'info_hash' => str_repeat('b', 40), 'name' => 't', 'user_id' => $this->user->id,
        ]);

        $types = app(Loader::class)->load();
        app(Classifier::class)->classify($torrent, $types['nfl_game'], ['season' => '2006', 'week' => '12']);

        $week = Term::where('level', 'week')->firstOrFail();

        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->call('deleteTerm', $week->id)
            ->assertHasErrors('terms');

        expect(Term::find($week->id))->not->toBeNull();
    });

    it('allows deleting a term nothing uses', function () {
        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->set('newTerm.level', 'season')
            ->set('newTerm.value', '2099')
            ->call('addTerm');

        $unused = Term::where('value', '2099')->firstOrFail();

        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->call('deleteTerm', $unused->id)
            ->assertHasNoErrors();

        expect(Term::find($unused->id))->toBeNull();
    });

    it('refuses to delete a term that has children', function () {
        // Deleting a season with weeks under it would orphan them silently.
        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->set('newTerm.level', 'season')
            ->set('newTerm.value', '2010')
            ->call('addTerm');

        $season = Term::where('value', '2010')->firstOrFail();

        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->set('newTerm.level', 'week')
            ->set('newTerm.parent', $season->id)
            ->set('newTerm.value', '3')
            ->call('addTerm');

        Livewire::test(TaxonomyAdmin::class)
            ->set('contentType', 'nfl_game')
            ->call('deleteTerm', $season->id)
            ->assertHasErrors('terms');

        expect(Term::find($season->id))->not->toBeNull();
    });
});

describe('curating a shared facet vocabulary', function () {
    it('adds a value to a vocabulary', function () {
        Livewire::test(TaxonomyAdmin::class)
            ->set('newFacetValue.facet', 'resolution')
            ->set('newFacetValue.value', '2160p')
            ->call('addFacetValue')
            ->assertHasNoErrors();

        expect(FacetValue::whereHas('facet', fn ($q) => $q->where('name', 'resolution'))
            ->where('value', '2160p')->exists())->toBeTrue();
    });

    it('drops a value an HD-only tracker does not want', function () {
        // The Spec's own example: "an HD-only tracker drops 480p from
        // resolution the same way a US-only tracker drops CFL from its
        // leagues."
        Livewire::test(TaxonomyAdmin::class)
            ->set('newFacetValue.facet', 'resolution')
            ->set('newFacetValue.value', '480p')
            ->call('addFacetValue');

        $value = FacetValue::where('value', '480p')->firstOrFail();

        Livewire::test(TaxonomyAdmin::class)
            ->call('deleteFacetValue', $value->id)
            ->assertHasNoErrors();

        expect(FacetValue::find($value->id))->toBeNull();
    });

    it('refuses to drop a value still assigned to torrents', function () {
        $torrent = Torrent::create([
            'info_hash' => str_repeat('c', 40), 'name' => 't', 'user_id' => $this->user->id,
        ]);

        $types = app(Loader::class)->load();
        app(Classifier::class)->classify($torrent, $types['nfl_game'], ['season' => '2006'], [
            'resolution' => ['1080p'],
        ]);

        $value = FacetValue::where('value', '1080p')->firstOrFail();

        Livewire::test(TaxonomyAdmin::class)
            ->call('deleteFacetValue', $value->id)
            ->assertHasErrors('facets');

        expect(FacetValue::find($value->id))->not->toBeNull();
    });

    it('refuses a facet no definition declares', function () {
        // Vocabularies are shared, but they are still the definitions' —
        // inventing one here would be inventing structure.
        Livewire::test(TaxonomyAdmin::class)
            ->set('newFacetValue.facet', 'invented')
            ->set('newFacetValue.value', 'x')
            ->call('addFacetValue')
            ->assertHasErrors('newFacetValue.facet');

        expect(Facet::where('name', 'invented')->exists())->toBeFalse();
    });
});

describe('what is installed, from where, at what version', function () {
    it('lists every content type with its source and version', function () {
        Livewire::test(TaxonomyAdmin::class)
            ->assertSee('NFL Game')
            ->assertSee('Cricket Match')
            ->assertSee('v1');
    });

    it('surfaces a pending upgrade and names the command rather than offering a button', function () {
        // CP5's confirmation is deliberately a command. The admin screen tells
        // them it exists; it does not reimplement it.
        $torrent = Torrent::create([
            'info_hash' => str_repeat('d', 40), 'name' => 't', 'user_id' => $this->user->id,
        ]);

        $types = app(Loader::class)->load();
        app(Classifier::class)->classify($torrent, $types['nfl_game'], ['season' => '2006']);

        // Ship a v2 over the top.
        $dir = sys_get_temp_dir().'/marque-admin-'.bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/nfl.yaml', "content_type: nfl_game\nlabel: NFL Game\nversion: 2\nlevels:\n  - season: { type: year }\n  - round: { type: integer, range: [1, 22] }\nmigrations:\n  - from: 1\n    rename_level: { from: week, to: round }\n");
        config()->set('taxonomy.definitions.path', $dir);

        Livewire::test(TaxonomyAdmin::class)
            ->assertSee('marque:taxonomy:upgrade nfl_game');

        array_map(unlink(...), glob($dir.'/*') ?: []);
        @rmdir($dir);
    });
});

describe('authorisation', function () {
    it('refuses a non-admin', function () {
        // This screen edits the shape of the catalogue. A ratio-tracked user
        // must not reach it.
        $this->actingAs($this->user);

        Livewire::test(TaxonomyAdmin::class)
            ->assertForbidden();
    });

    it('refuses a guest', function () {
        auth()->logout();

        Livewire::test(TaxonomyAdmin::class)
            ->assertForbidden();
    });
});
