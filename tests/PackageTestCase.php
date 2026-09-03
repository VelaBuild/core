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
     * The host app registers this in config/app.php, and the public templates
     * reach for it by its short name — without it no view that renders the
     * site chrome can be exercised here at all.
     */
    protected function getPackageAliases($app): array
    {
        return [
            'LaravelLocalization' => \Mcamara\LaravelLocalization\Facades\LaravelLocalization::class,
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

    /**
     * Anything that writes the site config writes a REAL file into the test
     * app's storage, and a test app boots by reading it. Left behind by one
     * test, it decides which theme is active for every test after it — and
     * for every later RUN, so a suite that passed can start failing on a
     * machine where nothing changed. Cleared here rather than in each test
     * that happens to touch it.
     */
    protected function tearDown(): void
    {
        @unlink(storage_path('app/vela-site.php'));

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        // Sessions, cookies and the CSRF middleware all need an encryption key,
        // and Testbench boots without one.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

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
