<?php

namespace VelaBuild\Core\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use VelaBuild\Core\Services\SiteHealth;
use VelaBuild\Core\Tests\PackageTestCase;

/**
 * A site can be running new code against an old database, or without a
 * package the code now needs. Both used to show up as a failure in front of
 * a visitor; they are said in the admin instead, with the command to run.
 */
class SiteHealthTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SiteHealth::forget();
    }

    private function health(): SiteHealth
    {
        return app(SiteHealth::class);
    }

    public function test_a_migration_the_database_has_not_run_is_reported_with_its_command(): void
    {
        $this->assertSame([], $this->health()->pendingMigrations(), 'The test database is migrated, so nothing should be waiting.');

        // As a site that updated the package and did not migrate looks.
        DB::table('migrations')->where('migration', 'like', '%create_vela_pages_table')->delete();
        SiteHealth::forget();

        $pending = $this->health()->pendingMigrations();
        $this->assertNotEmpty($pending);
        $this->assertStringContainsString('create_vela_pages_table', implode(' ', $pending));

        $notice = collect($this->health()->notices())->firstWhere('command', 'php artisan vela:update');
        $this->assertNotNull($notice, 'A pending migration should be one of the notices.');
        $this->assertStringContainsString('create_vela_pages_table', $notice['body']);
    }

    public function test_the_answer_is_kept_so_every_admin_page_does_not_ask_the_database(): void
    {
        $this->health()->pendingMigrations();

        $this->assertTrue(Cache::has('vela:site-health:pending-migrations'));

        SiteHealth::forget();
        $this->assertFalse(Cache::has('vela:site-health:pending-migrations'));
    }

    public function test_a_site_with_no_database_is_told_nothing_rather_than_broken(): void
    {
        DB::shouldReceive('table')->andThrow(new \RuntimeException('no such table: migrations'));

        $this->assertSame([], $this->health()->pendingMigrations());
    }

    public function test_the_mcp_gateway_is_registered_only_when_its_package_is_installed(): void
    {
        // It is installed here, so the route is registered; the guard around
        // it is what keeps a site without the package serving pages at all.
        $this->assertFalse($this->health()->mcpGatewayMissing());
        $this->assertNotEmpty(
            array_filter(Route::getRoutes()->getRoutes(), fn ($route) => $route->uri() === 'api/mcp'),
            'The gateway should be registered while laravel/mcp is installed.'
        );

        $provider = file_get_contents(__DIR__ . '/../../src/VelaServiceProvider.php');
        $this->assertMatchesRegularExpression(
            '/if \(!app\(.*SiteHealth::class\)->mcpGatewayMissing\(\)\) \{\s*\\\\Laravel\\\\Mcp/',
            $provider,
            'The gateway must only be registered when laravel/mcp is there: without the guard, a site '
            . 'missing the package answers every request, admin included, with a 500.'
        );
    }
}
