<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Livewire\ClassifierForm;
use Marque\Taxonomy\Models\Term;
use Marque\Taxonomy\Services\Classifier;
use Marque\Taxonomy\Tests\TestUser;
use Marque\Trove\Models\Torrent;

/**
 * The upload form. Dan, resolving open question 4:
 *
 * > "you pick NFL and then the next dropdown becomes the relevant NFL seasons
 * > etc and then choose one and we get weeks and possibly even the individual
 * > games after that?"
 *
 * More interactions than legacy's single 72-item dropdown, and that is the
 * right trade: it makes correct nesting the path of least resistance rather
 * than something an admin has to be disciplined about. The legacy `categories`
 * table is the counter-example — a `parent` column that existed and went
 * unused across all 72 rows, with the season baked into the name instead.
 */
beforeEach(function () {
    config()->set('taxonomy.definitions.path', __DIR__.'/../fixtures/taxonomies');

    $this->user = TestUser::create(['name' => 'u', 'email' => 'u@example.com', 'password' => 'x']);

    $this->torrent = Torrent::create([
        'info_hash' => str_repeat('a', 40),
        'name' => 'Eagles at Cowboys',
        'user_id' => $this->user->id,
    ]);

    // A little existing data so the cascade has something to offer.
    $classifier = app(Classifier::class);
    $types = app(Loader::class)->load();

    foreach ([['2006', '12'], ['2006', '13'], ['2007', '4']] as $i => [$season, $week]) {
        $other = Torrent::create([
            'info_hash' => str_pad((string) ($i + 10), 40, '0', STR_PAD_LEFT),
            'name' => 'Existing '.$i,
            'user_id' => $this->user->id,
        ]);

        $classifier->classify($other, $types['nfl_game'], ['season' => $season, 'week' => $week]);
    }
});

describe('picking a content type reshapes the form', function () {
    it('offers every defined content type', function () {
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->assertSee('NFL Game')
            ->assertSee('Cricket Match')
            ->assertSee('TV Episode')
            ->assertSee('Cycling Stage');
    });

    it('shows only the chosen type\'s levels', function () {
        // "A cycling uploader never sees Week." The whole point of scoping.
        //
        // Only the FIRST level renders on a fresh form — that is the cascade,
        // not an omission: `race` appears once a season is chosen. Asserting
        // Race and Stage were visible up front would have been asserting the
        // wall-of-selects design this checkpoint explicitly rejects.
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'cycling_stage')
            ->assertSee('Season')
            ->assertDontSee('Week')
            ->assertDontSee('Innings');
    });

    it('reveals the next level only once its parent is chosen', function () {
        // Asserted on the computed options rather than rendered text: "Stage"
        // also appears in the content-type dropdown as part of "Cycling
        // Stage", so assertDontSee('Stage') would fail for a reason that has
        // nothing to do with the cascade.
        $component = Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'cycling_stage');

        expect(array_keys($component->instance()->options()))->toBe(['season']);

        $component->set('path.season', '2006');

        // Race is now offered — empty, because this tracker has no cycling
        // terms yet, but offered, so the uploader can type one.
        expect(array_keys($component->instance()->options()))->toBe(['season', 'race']);
    });

    it('shows only the chosen type\'s facets', function () {
        // Cycling declares resolution and source; TV also declares audio.
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'cycling_stage')
            ->assertDontSee('Audio');
    });

    it('resets a part-built path when the type changes', function () {
        // Otherwise a season chosen under NFL would linger while the form now
        // shows cricket's levels — a path half-belonging to two types.
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->set('path.season', '2006')
            ->set('contentType', 'cricket_match')
            ->assertSet('path', []);
    });
});

describe('the cascade', function () {
    it('offers only the first level until something is chosen', function () {
        $component = Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game');

        expect($component->get('options'))->toHaveKey('season')
            ->and($component->get('options'))->not->toHaveKey('week');
    });

    it('populates the next level from the chosen parent', function () {
        // The dependent half: pick 2006, get 2006's weeks.
        $component = Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->set('path.season', '2006');

        expect(array_values($component->get('options')['week']))->toBe(['12', '13']);
    });

    it('offers a different parent\'s children when the parent changes', function () {
        $component = Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->set('path.season', '2007');

        expect(array_values($component->get('options')['week']))->toBe(['4']);
    });

    it('clears deeper choices when a shallower one changes', function () {
        // Week 12 belongs to 2006. Switching to 2007 must not leave it
        // selected, or the form submits a path that never existed.
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->set('path.season', '2006')
            ->set('path.week', '12')
            ->set('path.season', '2007')
            ->assertSet('path.week', null);
    });

    it('lets an uploader add a value that does not exist yet', function () {
        // A tracker's first 2008 game has no 2008 term. Requiring an admin to
        // pre-create every season before anyone can upload would make the form
        // useless on a new tracker.
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->set('path.season', '2008')
            ->set('path.week', '1')
            ->call('save')
            ->assertHasNoErrors();

        expect(Term::where('level', 'season')->where('value', '2008')->exists())->toBeTrue();
    });
});

describe('validation is the definition, enforced server-side', function () {
    it('refuses a value outside a declared range', function () {
        // week: { range: [1, 22] }. Enforced here, not merely in the browser —
        // a crafted request must not get past it.
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->set('path.season', '2006')
            ->set('path.week', '47')
            ->call('save')
            ->assertHasErrors('path.week');
    });

    it('refuses a value of the wrong type', function () {
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->set('path.season', 'last year')
            ->call('save')
            ->assertHasErrors('path.season');
    });

    it('requires a content type', function () {
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->call('save')
            ->assertHasErrors('contentType');
    });

    it('requires at least the first level', function () {
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->call('save')
            ->assertHasErrors();
    });

    it('refuses a gap in the path', function () {
        // Week without season. The cascade makes this hard to do by hand, but
        // the form is not the only way in.
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->set('path.week', '12')
            ->call('save')
            ->assertHasErrors();
    });

    it('applies each type\'s own ranges', function () {
        // 14 is a valid cycling stage and an invalid cricket innings.
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'cricket_match')
            ->set('path.season', '2006')
            ->set('path.format', 'Test')
            ->set('path.innings', '14')
            ->call('save')
            ->assertHasErrors('path.innings');
    });
});

describe('facets', function () {
    it('accepts many values of one facet', function () {
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->set('path.season', '2006')
            ->set('path.week', '12')
            ->set('facets.subtitles', ['en', 'es', 'fr', 'de', 'ja', 'ko', 'pt', 'it', 'nl', 'pl', 'sv', 'da'])
            ->call('save')
            ->assertHasNoErrors();

        expect($this->torrent->taxonomyFacetValues()->count())->toBe(12);
    });

    it('ignores a facet the type does not declare', function () {
        // Not an error — a stale field from a previous type selection should
        // not block an otherwise valid upload. It simply is not applied.
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'cycling_stage')
            ->set('path.season', '2006')
            ->set('path.race', 'TdF')
            ->set('path.stage', '14')
            ->set('facets.audio', ['atmos'])
            ->call('save')
            ->assertHasNoErrors();

        expect($this->torrent->taxonomyFacetValues()->count())->toBe(0);
    });
});

describe('saving', function () {
    it('classifies the torrent and says so', function () {
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->set('path.season', '2006')
            ->set('path.week', '12')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('taxonomy-classified');

        expect($this->torrent->taxonomyClassifications()->count())->toBe(1)
            ->and($this->torrent->taxonomyClassifications()->first()->term->value)->toBe('12');
    });

    it('reuses the existing term rather than making a second one', function () {
        $before = Term::where('level', 'week')->where('value', '12')->count();

        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->set('path.season', '2006')
            ->set('path.week', '12')
            ->call('save');

        expect(Term::where('level', 'week')->where('value', '12')->count())->toBe($before);
    });

    it('carries a grouping key when one is supplied', function () {
        // The key ships in v1 (CP2) and is readable (CP4); this is the first
        // place a person could set one. The *entity picker* that would derive
        // it automatically is deferred — see the checkpoint note.
        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->set('contentType', 'nfl_game')
            ->set('path.season', '2006')
            ->set('path.week', '12')
            ->set('groupingKey', 'nfl:2006:12:phi-dal')
            ->call('save');

        expect($this->torrent->taxonomyClassifications()->first()->grouping_key)
            ->toBe('nfl:2006:12:phi-dal');
    });

    it('loads an existing classification when reopened', function () {
        // Editing a classified torrent should show what it already is, not an
        // empty form that silently reclassifies on save.
        app(Classifier::class)->classify(
            $this->torrent,
            app(Loader::class)->load()['nfl_game'],
            ['season' => '2006', 'week' => '13'],
        );

        Livewire::test(ClassifierForm::class, ['torrent' => $this->torrent])
            ->assertSet('contentType', 'nfl_game')
            ->assertSet('path.season', '2006')
            ->assertSet('path.week', '13');
    });
});
