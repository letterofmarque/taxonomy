# Marque Taxonomy

Declarative content-type engine for the [Marque](https://github.com/letterofmarque/marque)
tracker platform. A tracker declares its own shape in YAML — its hierarchy levels, its
facets — instead of having that shape hardcoded in schema.

```
  taxonomy-sport ─┐                              ┌─ upload form
  taxonomy-tv    ─┼─→  content-type definition ─→┼─ validation
  taxonomy-film  ─┤          (YAML)              ├─ query builder
  your own       ─┘                              └─ admin UI
```

## Why

Existing tracker software makes the tracker's *domain* a schema decision. Gazelle carries
`media`, `format`, `encoding`, `remaster_year` as columns on `torrents` — a music shape
wearing a config file. Run a multi-sport tracker at it (cricket has innings and
Test/ODI/T20; swimming has heats, semis and finals; cycling has stages) and each sport is a
schema change, a rewritten upload form and a rewritten search builder.

That is a fork, not a configuration.

Marque answers it with a file per domain. Generic tables underneath, runtime definitions on
top, and no admin-triggered migrations — because the failure mode of generated migrations
is someone renaming a level at 2am, a migration half-applying, and a catalogue broken in a
way they cannot undo. With runtime definitions the worst case is "the definition did not
load, nothing changed."

The engine ships **no domain vocabulary at all**. It does not know what a sport, a season,
a series or an episode is; a test greps the source to keep it that way. Domain packages
supply that.

### The specific problem it solves

A free-form category tree lets the same concept sit at inconsistent depth — a real tracker's
export had 416 week-nodes at one depth and 219 at another, because one league needed a
division level and another did not. Once that happens, "all week 12 games" is unwritable as
a query. The flat-list alternative fails differently: a `parent` column nobody uses and the
season baked into every category name (`NFL 2013`, `NFL 2014`, …), one row per season,
by hand, forever.

Here, levels are **typed dimensions scoped to a content type** rather than nodes at a
depth. So these are single queries, spanning every domain that declares the level:

```php
$query->where('season', '2006')->get();   // every league's 2006
$query->where('week', '12')->get();       // every season's week 12
```

## Installation

```bash
composer require marque/taxonomy
```

You generally will not install this directly — it arrives as a dependency of whichever
domain package you picked (`marque/taxonomy-sport`, `marque/taxonomy-tv`,
`marque/taxonomy-film`).

```bash
php artisan migrate
php artisan vendor:publish --tag=taxonomy-config
php artisan vendor:publish --tag=taxonomy-views    # optional, to restyle the UI
```

Requires `marque/trove` (torrent model). `livewire/livewire` is a **suggestion**, not a
requirement — the upload form and admin screen need it, and an API-only install classifies
through the service without it.

## Writing a content type

This is the API. A content type is one YAML file in `config/taxonomies/`.

```yaml
content_type: nfl_game          # identifier: lowercase, digits, underscores
label: NFL Game                 # what a human sees
version: 1                      # see Versioning below

levels:                         # ORDER IS THE HIERARCHY
  - season: { label: Season, type: year }
  - week:   { label: Week, type: integer, range: [1, 22] }

facets: [resolution, source, codec, subtitles]
```

That is a complete, working content type. No migration, no deploy, no PHP.

### Levels

Ordered — the list order *is* the parent/child relationship, so `season` contains `week`.

| Key | Meaning |
|---|---|
| `label` | Display name. Defaults to the level's own name. |
| `type` | `string`, `integer` or `year`. Unknown types are refused. |
| `range` | `[min, max]`, for `integer` and `year` only. |

Levels are **scoped to their content type**. Two types may both declare `week` and they are
completely independent — an NFL uploader and a TV uploader never see each other's fields,
and each type's `range` applies only to its own. `14` is a valid cycling stage and an
invalid cricket innings, at the same time, on the same tracker.

### Facets

Flat vocabularies, **shared across content types**: `resolution` means the same thing
everywhere. A definition names them; it does not define them.

Facets are **many-valued** by default, because a modern rip carries a dozen subtitle tracks
and half a dozen audio tracks. Filtering on any one value finds the torrent.

### A second domain is a second file

```yaml
content_type: cricket_match
label: Cricket Match
version: 1

levels:
  - season: { label: Season, type: year }
  - format: { label: Format, type: string }
  - innings: { label: Innings, type: integer, range: [1, 4] }

facets: [resolution, source, subtitles]
```

Different depth, different leaf, `season` shared with NFL and colliding with nothing. This
is the whole claim of the package, and the test suite proves it against four structurally
different domains plus a fifth built at runtime.

## Validation

Strict, deliberately — a definition is executable configuration, so a typo that loads is a
typo that breaks a catalogue quietly.

```bash
php artisan marque:taxonomy:validate
```

Every reference must resolve, no duplicate names within a type, declared types and ranges
must be well-formed. **A definition loads completely or not at all**: one bad file fails the
whole load, so the tracker keeps running on its previous definitions rather than a
half-applied taxonomy. Every problem is reported at once, and the message names the file.

Exits non-zero, so it works as a deploy gate.

## Changing a definition later

Two situations, same protective posture, because from the admin's side the risk is
identical.

**Additive changes** — a new facet, a new level value — apply on load. No ceremony.

**Destructive changes** — removing a level, dropping a facet — never apply silently. And
classification data is **never deleted**: rows become unreferenced and recoverable, enforced
by the schema's foreign keys rather than by politeness.

**A version bump requires a migration path, or it is refused.** Not warned about — refused.
The package author is the only person who knows both the old shape and the new one, so
making the admin infer it is backwards:

```yaml
content_type: nfl_game
version: 2

levels:
  - season: { type: year }
  - round:  { type: integer, range: [1, 22] }

migrations:
  - from: 1
    rename_level: { from: week, to: round }
```

Declared like that, every existing classification survives the rename. Without the
declaration a naive loader sees `week` vanish and `round` appear, and orphans the lot.

`composer update` never reshapes a live catalogue. The loader notices and stops:

```
nfl_game has an update available (v1 → v2). 4000 torrent(s) are classified under it.
Run `marque:taxonomy:upgrade nfl_game` to review and apply.
```

The command reports what changes and how many torrents are affected, then asks. Steps
available: `add_level`, `remove_level`, `rename_level`, `add_facet`, `remove_facet`.

### When to ship a new package instead

Renaming a level is a migration. A content type that is *no longer the same type* —
"this was NFL Game and is now a generic Sports Fixture with different levels entirely" — is
a new package, an abandonment of the old, and a one-off migration. Deliberately a judgement
call rather than a mechanical boundary; an author who cannot tell which side they are on
should ship a new package, because that forces the admin's consent.

## Domain packages

**The `taxonomy-` prefix is convention, not enforcement.** Name a domain package
`marque/taxonomy-sport`, `marque/taxonomy-tv`, `marque/taxonomy-film` — someone wanting a
film tracker should be able to find it by searching for one. The loader does not care what
your package is called, so a private definition set inside your own app package works fine.

Register your definitions from your service provider:

```php
config()->push('taxonomy.definitions.packages', __DIR__.'/../definitions');
```

**The app always wins.** A definition in `config/taxonomies/` overrides a package-shipped
one of the same name, and a package update never silently replaces an override — though the
admin is told a newer version exists.

## Classifying from code

```php
use Marque\Taxonomy\Contracts\ClassifiesTorrents;

app(ClassifiesTorrents::class)->classify(
    $torrent,
    $contentType,
    ['season' => '2006', 'week' => '12'],
    ['resolution' => ['1080p'], 'subtitles' => ['en', 'es', 'fr']],
    'nfl:2006:12:phi-dal',   // optional grouping key
);
```

The declared ranges are enforced here, not just in the form. A path may not skip a level —
there is no "week 12" independent of which season's week 12 it is.

### The grouping key

One nullable identifier meaning "these torrents are the same underlying thing" — four
encodes of one game group together. **Core stores it and never interprets it.** What makes
two torrents the same thing is the domain package's business; a content type that finds it
meaningless leaves it null and nothing degrades.

## The admin screen

`<livewire:taxonomy-admin />` lets an admin populate declared levels and curate shared
vocabularies — which leagues, which seasons, which resolutions your tracker actually
carries.

It offers **no way to create, rename or remove a level.** That is not an oversight: levels
come from the definition, and an admin who can only add values cannot produce either of the
failure modes described above. Structural change goes through the commands, which report
what they will orphan before doing anything.

### Reaching it

Taxonomy registers the screen with `marque/trove`'s `AdminScreenRegistry`, so installing
[`marque/skipper`](https://github.com/letterofmarque/skipper) — the admin panel — puts
**Taxonomy** under *Content* in the panel automatically, at `/admin/taxonomy`, visible to
admins.

**Skipper is optional and taxonomy does not require it.** With no panel installed the
registration is simply never read, and nothing else changes: mount
`<livewire:taxonomy-admin />` on a route of your own and gate it however you like. The
component authorises independently either way — it requires an admin on mount and on every
write, rather than trusting whatever middleware the surrounding route happens to carry.

## Not in this package

- **The browse and filter surface.** The query engine is here; `guise`/`disguise`
  browse-by-path and `cennad` filter parameters are their own work.
- **Release-name parsing.** `Show.Name.S01E05.1080p.WEB-DL-GROUP` carries exactly the
  fields a taxonomy wants, and a parser would prefill the upload form — genuinely valuable,
  not necessary, and better placed with other automation.
- **Per-content-type entity fields.** The grouping key ships; the machinery that would
  derive it from an entity picker does not.
- **Any domain vocabulary.** Deliberately. That is what `marque/taxonomy-*` is for.

## Requirements

- PHP 8.3+
- Laravel 13+
- `marque/trove` 4.0+

Runs on SQLite, MySQL, MariaDB and PostgreSQL — the full suite is exercised against all
four, not merely claimed.

## Licence

MIT. See [LICENCE](../../LICENSE).
