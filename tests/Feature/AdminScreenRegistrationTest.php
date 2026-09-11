<?php

declare(strict_types=1);

use Livewire\Livewire;
use Marque\Taxonomy\Definitions\Loader;
use Marque\Taxonomy\Livewire\TaxonomyAdmin;
use Marque\Trove\Enums\Role;
use Marque\Trove\Registry\AdminScreenRegistry;

describe('taxonomy admin screen registration', function () {
    it('registers the admin screen', function () {
        $screen = app(AdminScreenRegistry::class)->find('taxonomy');

        expect($screen)->not->toBeNull()
            ->and($screen->label)->toBe('Taxonomy')
            ->and($screen->component)->toBe('taxonomy-admin');
    });

    // TaxonomyAdmin::authorizeAdmin() throws unless the user is at least an
    // admin. The declared floor has to match, or the registry and the component
    // would disagree — the panel offering a link that then throws.
    it('declares an admin floor, matching the component', function () {
        expect(app(AdminScreenRegistry::class)->find('taxonomy')->minimumRole)
            ->toBe(Role::Admin);
    });

    it('is visible to admins only', function () {
        $registry = app(AdminScreenRegistry::class);

        expect($registry->allows('taxonomy', Role::Admin))->toBeTrue()
            ->and($registry->allows('taxonomy', Role::Moderator))->toBeFalse()
            ->and($registry->allows('taxonomy', Role::Uploader))->toBeFalse()
            ->and($registry->allows('taxonomy', Role::User))->toBeFalse();
    });

    it('groups the screen under Content', function () {
        expect(app(AdminScreenRegistry::class)->find('taxonomy')->group)
            ->toBe('Content');
    });

    // Unlike usarrs, taxonomy binds NO routes of its own — this is the package
    // that shipped an admin surface with no way to reach it, which is the whole
    // reason Spec #108 exists. It declares a path and lets the panel route it.
    it('declares a path for the panel to route', function () {
        expect(app(AdminScreenRegistry::class)->find('taxonomy')->path)
            ->toBe('admin/taxonomy');
    });

    it('binds no admin route of its own', function () {
        // If taxonomy ever starts binding its own route, skipper will stop
        // generating one (CP #591) — and that is fine, but it is a deliberate
        // change rather than something to happen by accident.
        expect(app('router')->has('admin.taxonomy.index'))->toBeFalse();
    });

    it('names a component Livewire can actually resolve', function () {
        $screen = app(AdminScreenRegistry::class)->find('taxonomy');

        expect(Livewire::new($screen->component))->toBeInstanceOf(TaxonomyAdmin::class);
    });
});

describe('taxonomy does not depend on skipper', function () {
    it('names skipper nowhere in its composer manifest', function () {
        expect(file_get_contents(__DIR__.'/../../composer.json'))
            ->not->toContain('marque/skipper');
    });

    it('names skipper nowhere in its source', function () {
        $hits = [];

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../src')
        ) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')
                && str_contains(file_get_contents($file->getPathname()), 'Skipper')) {
                $hits[] = $file->getFilename();
            }
        }

        expect($hits)->toBe([]);
    });

    // This suite runs with no SkipperServiceProvider registered. Registration
    // is unconditional: taxonomy cannot know whether a panel exists, and an
    // unread registry entry costs nothing.
    it('registers the screen even with no panel installed', function () {
        expect(app(AdminScreenRegistry::class)->find('taxonomy'))->not->toBeNull();
    });

    it('leaves the rest of taxonomy working with no panel', function () {
        // The classifier and the definitions layer are the package's real job
        // and have nothing to do with the admin surface.
        expect(app(Loader::class)->load())->toBeArray();
    });
});
