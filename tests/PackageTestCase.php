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
     * Bind a double, and make sure the console can still see it.
     *
     * Artisan builds its commands once, out of the container, when the console
     * kernel first boots — and that happens before a test body runs. A command
     * taking its dependency in the constructor therefore went on holding the
     * real one however carefully a test swapped the binding afterwards, which
     * is why a shelf of command tests failed with "No AI text provider
     * configured" while mocking a manager that said the opposite.
     *
     * Dropping the Artisan application makes the kernel build its commands
     * again, from the container as it stands now.
     */
    protected function instance($abstract, $instance)
    {
        $result = parent::instance($abstract, $instance);

        if ($this->app->resolved(\Illuminate\Contracts\Console\Kernel::class)) {
            $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);
        }

        return $result;
    }

    /**
     * Give a provider an API key, or take it away.
     *
     * Provider keys used to be config values, and a shelf of tests still set
     * `vela.ai.openai.api_key` and wondered why nothing resolved. They live in
     * AiSettingsService now — the same place Settings → AI writes them — so
     * this is the only way to arrange a test around one.
     *
     * @param array<string, ?string> $keys provider name => key, or null to clear
     */
    protected function setAiKeys(array $keys): void
    {
        $settings = app(\VelaBuild\Core\Services\AiSettingsService::class);

        foreach ($keys as $provider => $value) {
            $settings->set($provider . '_api_key', $value);
        }
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
