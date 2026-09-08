<?php

declare(strict_types=1);

use Marque\Taxonomy\Definitions\ContentType;
use Marque\Taxonomy\Exceptions\InvalidDefinitionException;
use Marque\Taxonomy\Exceptions\UpgradeRefusedException;
use Marque\Taxonomy\Models\InstalledVersion;
use Marque\Taxonomy\Models\Term;
use Marque\Taxonomy\Services\Classifier;
use Marque\Taxonomy\Services\Upgrader;
use Marque\Taxonomy\Tests\TestUser;
use Marque\Trove\Models\Torrent;

/**
 * `marque:taxonomy:upgrade` — the explicit command that stands between
 * `composer update` and a reshaped catalogue.
 *
 * The confirmation is the mechanism. It must show what changes and how many
 * torrents are affected BEFORE asking, because "are you sure?" with no numbers
 * is the dialog everyone clicks through.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/marque-tax-upgrade-'.bin2hex(random_bytes(6));
    mkdir($this->dir, 0777, true);
    config()->set('taxonomy.definitions.path', $this->dir);

    $v1 = ContentType::fromArray([
        'content_type' => 'nfl_game',
        'label' => 'NFL Game',
        'version' => 1,
        'levels' => [
            ['season' => ['type' => 'year']],
            ['week' => ['type' => 'integer', 'range' => [1, 22]]],
        ],
    ]);

    $user = TestUser::create(['name' => 'u', 'email' => 'u@example.com', 'password' => 'x']);

    foreach (['12', '13'] as $i => $week) {
        $torrent = Torrent::create([
            'info_hash' => str_pad((string) ($i + 1), 40, '0', STR_PAD_LEFT),
            'name' => 'Game '.$week,
            'user_id' => $user->id,
        ]);

        app(Classifier::class)->classify($torrent, $v1, ['season' => '2006', 'week' => $week]);
    }

    $this->shipV2 = function (string $yaml): void {
        file_put_contents($this->dir.'/nfl.yaml', $yaml);
    };
});

afterEach(function () {
    array_map(unlink(...), glob($this->dir.'/*') ?: []);
    @rmdir($this->dir);
});

it('reports what changes and how many torrents are affected before asking', function () {
    ($this->shipV2)("content_type: nfl_game\nlabel: NFL Game\nversion: 2\nlevels:\n  - season: { type: year }\n  - round: { type: integer, range: [1, 22] }\nmigrations:\n  - from: 1\n    rename_level: { from: week, to: round }\n");

    // Asserted as one string rather than chained expectsOutputToContain calls:
    // those consume sequentially, so two assertions against the same rendered
    // line cannot both match. The version pair and the affected count share a
    // line here by design — that is the sentence an admin reads.
    $this->artisan('marque:taxonomy:upgrade nfl_game')
        ->expectsOutputToContain('nfl_game has an update available (v1 → v2)')
        // The count is what makes the decision real.
        ->expectsOutputToContain('2 torrent(s) are classified under it')
        ->expectsConfirmation('Apply this upgrade?', 'no')
        ->assertExitCode(1);
});

it('changes nothing when the admin declines', function () {
    ($this->shipV2)("content_type: nfl_game\nlabel: NFL Game\nversion: 2\nlevels:\n  - season: { type: year }\n  - round: { type: integer, range: [1, 22] }\nmigrations:\n  - from: 1\n    rename_level: { from: week, to: round }\n");

    $this->artisan('marque:taxonomy:upgrade nfl_game')
        ->expectsConfirmation('Apply this upgrade?', 'no');

    expect(Term::where('level', 'week')->count())->toBe(2)
        ->and(InstalledVersion::for('nfl_game')?->version)->toBe(1);
});

it('applies the upgrade when confirmed', function () {
    ($this->shipV2)("content_type: nfl_game\nlabel: NFL Game\nversion: 2\nlevels:\n  - season: { type: year }\n  - round: { type: integer, range: [1, 22] }\nmigrations:\n  - from: 1\n    rename_level: { from: week, to: round }\n");

    $this->artisan('marque:taxonomy:upgrade nfl_game')
        ->expectsConfirmation('Apply this upgrade?', 'yes')
        ->assertExitCode(0);

    expect(Term::where('level', 'round')->count())->toBe(2)
        ->and(Term::where('level', 'week')->count())->toBe(0)
        ->and(InstalledVersion::for('nfl_game')?->version)->toBe(2);
});

it('refuses a version bump with no migration path, without asking', function () {
    // Not a warning, not a confirmation — a refusal, and it lands one layer
    // earlier than first written: the LOADER rejects a pathless version bump
    // as an invalid definition, so the file never reaches the command at all.
    //
    // That is the stronger placement. A definition that cannot be upgraded
    // from is malformed rather than merely awkward, and refusing it at load
    // means the tracker keeps running on its previous definitions instead of
    // adopting a shape nobody described. The command's own refusal (see
    // Upgrader::apply) remains as the backstop for a path that breaks between
    // load and apply.
    ($this->shipV2)("content_type: nfl_game\nlabel: NFL Game\nversion: 2\nlevels:\n  - season: { type: year }\n");

    expect(fn () => $this->artisan('marque:taxonomy:upgrade nfl_game')->run())
        ->toThrow(InvalidDefinitionException::class);

    // The property that actually matters: the catalogue is untouched.
    expect(InstalledVersion::for('nfl_game')?->version)->toBe(1);
});

it('still refuses at the upgrader if a path breaks after loading', function () {
    // The backstop. The loader catches a pathless bump in a file, but
    // Upgrader::apply is reachable directly by a domain package building a
    // ContentType in code, so it refuses independently rather than trusting
    // that validation already happened.
    $unpathed = ContentType::fromArray([
        'content_type' => 'nfl_game',
        'label' => 'NFL Game',
        'version' => 2,
        'levels' => [['season' => ['type' => 'year']]],
    ]);

    expect(fn () => app(Upgrader::class)->apply($unpathed))
        ->toThrow(UpgradeRefusedException::class);

    expect(InstalledVersion::for('nfl_game')?->version)->toBe(1);
});

it('says there is nothing to do when already current', function () {
    ($this->shipV2)("content_type: nfl_game\nlabel: NFL Game\nversion: 1\nlevels:\n  - season: { type: year }\n  - week: { type: integer, range: [1, 22] }\n");

    $this->artisan('marque:taxonomy:upgrade nfl_game')->assertExitCode(0);
});

it('warns loudly and requires confirmation for a destructive step', function () {
    // Ceremony scales with damage: this one orphans data, so the report says
    // so in as many words before the prompt.
    ($this->shipV2)("content_type: nfl_game\nlabel: NFL Game\nversion: 2\nlevels:\n  - season: { type: year }\nmigrations:\n  - from: 1\n    remove_level: [week]\n");

    $this->artisan('marque:taxonomy:upgrade nfl_game')
        ->expectsOutputToContain('orphan')
        ->expectsConfirmation('Apply this upgrade?', 'no')
        ->assertExitCode(1);
});

it('surfaces a pending upgrade from the validate command too', function () {
    // An admin running the routine check should learn about it without having
    // to know the upgrade command exists.
    ($this->shipV2)("content_type: nfl_game\nlabel: NFL Game\nversion: 2\nlevels:\n  - season: { type: year }\n  - round: { type: integer, range: [1, 22] }\nmigrations:\n  - from: 1\n    rename_level: { from: week, to: round }\n");

    $this->artisan('marque:taxonomy:validate')
        ->expectsOutputToContain('marque:taxonomy:upgrade nfl_game')
        ->assertExitCode(0);
});
