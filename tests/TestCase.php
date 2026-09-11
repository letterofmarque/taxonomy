<?php

declare(strict_types=1);

namespace Marque\Taxonomy\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\LivewireServiceProvider;
use Marque\Taxonomy\TaxonomyServiceProvider;
use Marque\Trove\TroveServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /**
     * Every dependency is listed explicitly: Laravel's package auto-discovery
     * does not run under Testbench, so a provider left out here is simply
     * absent. guise's suite broke exactly this way on a missing
     * DeckServiceProvider.
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            TroveServiceProvider::class,
            TaxonomyServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // Torrents need an owner, and trove reaches the host's user model
        // through config rather than shipping one.
        $app['config']->set('trove.user_model', TestUser::class);
        $app['config']->set('auth.providers.users.model', TestUser::class);

        $app['config']->set('database.default', 'testing');
        // SQLite in memory by default. Marque is DB-agnostic (docs/why.md) and
        // that claim is only worth anything if it is exercised, so the suite
        // can be pointed at a real engine:
        //
        //   DB_CONNECTION=mysql DB_DATABASE=marque_test composer test
        //
        // A green SQLite run does not prove MySQL works — different engines
        // disagree about index length, strict mode, and aggregate typing.
        $app['config']->set('database.connections.testing', match (env('DB_CONNECTION', 'sqlite')) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'marque_test'),
                'username' => env('DB_USERNAME', 'marque'),
                'password' => env('DB_PASSWORD', 'marque'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'mariadb' => [
                'driver' => 'mariadb',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'marque_test'),
                'username' => env('DB_USERNAME', 'marque'),
                'password' => env('DB_PASSWORD', 'marque'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'marque_test'),
                'username' => env('DB_USERNAME', 'marque'),
                'password' => env('DB_PASSWORD', 'marque'),
                'charset' => 'utf8',
                'prefix' => '',
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                // SQLite defaults to foreign keys OFF, which silently makes
                // every cascadeOnDelete and constrained() in the schema
                // untested. MySQL and Postgres enforce them unconditionally,
                // so leaving this off means the cheapest engine to run is also
                // the one that proves the least.
                'foreign_key_constraints' => true,
            ],
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        // The fixture first: trove's migrations add columns to `users`, which
        // is the host app's table and which no package in the suite ships.
        $this->loadMigrationsFrom(__DIR__.'/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
