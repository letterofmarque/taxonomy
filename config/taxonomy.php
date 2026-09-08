<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where definitions come from
    |--------------------------------------------------------------------------
    |
    | Two sources, and the app wins.
    |
    | `packages` is where domain packages (marque/taxonomy-sport,
    | marque/taxonomy-tv) register their shipped defaults — normally by pushing
    | onto this array from their own service provider, not by an admin editing
    | it. `path` is the app's own directory, and a definition there overrides a
    | package one of the same content-type name.
    |
    | The consequence is the point: `composer update` never silently reshapes a
    | definition you have taken ownership of. You are told a newer version
    | exists — see `marque:taxonomy:validate` — and applying it stays your
    | decision.
    |
    */

    'definitions' => [
        'packages' => [],

        'path' => config_path('taxonomies'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Deliberately absent in v1, and worth explaining rather than leaving as an
    | apparent oversight.
    |
    | Definitions are parsed per request. A taxonomy is a handful of small YAML
    | files, so that costs roughly what reading a config file costs — real, but
    | not obviously worth the failure mode a cache introduces, which is an
    | admin editing a definition and seeing nothing change until they remember
    | to clear it. That is the same trap Laravel's own config cache sets, and
    | it is a nastier one here because the symptom is a silently stale upload
    | form rather than an obviously stale setting.
    |
    | If profiling on a real tracker shows this matters, the shape to add is a
    | `marque:taxonomy:cache` command mirroring `config:cache` — opt-in, so the
    | staleness is something you chose. Measure first.
    |
    */

];
