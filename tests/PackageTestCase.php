<?php

namespace VelaBuild\Core\Tests;

use Orchestra\Testbench\TestCase as TestbenchTestCase;
use VelaBuild\Core\VelaServiceProvider;

/**
 * A self-contained base for tests that exercise the package on its own.
 *
 * The older TestCase in this directory expects a host application to supply
 * `Tests\CreatesApplication`, which no longer exists anywhere in the repo, so
 * nothing extending it can boot. This one stands the package up through
 * Testbench against an in-memory SQLite database instead, which is what the
 * declared orchestra/testbench dev dependency is for.
 */
abstract class PackageTestCase extends TestbenchTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            // Registered by the host app in production; Testbench boots a bare
            // application, so the package's own dependencies come from here.
            \Mcamara\LaravelLocalization\LaravelLocalizationServiceProvider::class,
            \Spatie\MediaLibrary\MediaLibraryServiceProvider::class,
            VelaServiceProvider::class,
        ];
    }

    /**
     * A signed-in user for tools that record an action log against one.
     */
    protected function signIn(): \VelaBuild\Core\Models\VelaUser
    {
        $user = \VelaBuild\Core\Models\VelaUser::create([
            'name'     => 'Test Admin',
            'email'    => 'admin@example.test',
            'password' => 'secret-password',
        ]);

        $this->actingAs($user, 'vela');

        return $user;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Run them rather than loadMigrationsFrom(), which also schedules a
        // rollback afterwards — and the marketplace-permissions migration's
        // down() calls Permission::roles(), a relation the model does not
        // define, so every teardown would fail on an unrelated error.
        $this->artisan('migrate', [
            '--path'     => __DIR__ . '/../database/migrations',
            '--realpath' => true,
        ])->run();
    }
}
