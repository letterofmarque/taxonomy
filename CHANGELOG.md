# Changelog

All notable changes to `marque/taxonomy` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-09

> A tracker declares its own taxonomy in YAML — hierarchy levels, facets, and how to
> migrate between versions of a definition — instead of having that shape hardcoded in
> schema. Adding a domain is a file, not a fork.

### Added

- **Content types declared in YAML.** Ordered levels (`string`, `integer`, `year`, with
  optional ranges) and shared facet vocabularies. No migration, no deploy, no PHP.
- **Levels are typed dimensions scoped to a content type**, so `week` on one type and
  `week` on another are independent, and "all week 12 games" is a single query across every
  league regardless of what depth `week` sits at in each.
- **Generic schema** — terms (materialised path), facets and their values, many-to-many
  assignments, and per-torrent classification carrying a nullable grouping key.
- **Strict validation**, with `marque:taxonomy:validate` as a deploy gate. A definition
  loads completely or not at all: one bad file leaves the tracker on its previous
  definitions rather than a half-applied taxonomy.
- **Declared migration paths.** A version bump with no migration path is refused, not
  warned about. `rename_level` preserves every existing classification through a rename
  that a naive loader would turn into mass orphaning.
- **`marque:taxonomy:upgrade`**, which reports what changes and how many torrents are
  affected before asking. `composer update` never reshapes a live catalogue.
- **Classification data is never deleted by a definition edit** — orphaned rows remain and
  are recoverable, enforced by foreign keys rather than by convention.
- **Cascading upload form** (`<livewire:taxonomy-classifier-form />`) — pick a content
  type, then walk its hierarchy one level at a time. A cycling uploader never sees `Week`.
- **Admin screen** (`<livewire:taxonomy-admin />`) for populating declared levels and
  curating vocabularies. Deliberately offers no way to create or remove a level.
- App definitions override package-shipped ones of the same name, and a package update
  never silently replaces an override.

### Notes

- The engine ships **no domain vocabulary**. A test tokenises the source and greps it to
  keep it that way.
- `livewire/livewire` is a suggestion rather than a requirement: an API-only install
  classifies through the service without a frontend stack.
- Runs on SQLite, MySQL, MariaDB and PostgreSQL, exercised against all four.
