<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * What is out of step between the installed package and the site running it.
 *
 * Updating Vela is two steps — `composer update velabuild/core`, then
 * `php artisan vela:update` — and the starter only runs the half of the
 * second that touches files (`--no-migrate`). A site that skipped the rest
 * showed nothing at all about it: code that wanted a table the database did
 * not have, or a package the vendor folder did not have, failed where a
 * visitor could see it and nowhere an owner would look.
 *
 * So the admin says it, on every page, with the command to run.
 */
class SiteHealth
{
    /** Long enough not to query on every click, short enough to clear itself. */
    private const CACHE_SECONDS = 300;

    private const CACHE_KEY = 'vela:site-health:pending-migrations';

    /**
     * The package's own migrations the database has not run.
     *
     * @return string[] migration names, oldest first
     */
    public function pendingMigrations(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function () {
            try {
                $ran = DB::table('migrations')->pluck('migration')->all();
            } catch (\Throwable $e) {
                // No database, or not installed yet: nothing to report, and an
                // exception here would take the admin down with it.
                return [];
            }

            $ours = collect(File::glob(__DIR__ . '/../../database/migrations/*.php'))
                ->map(fn ($path) => basename($path, '.php'))
                ->values()
                ->all();

            return array_values(array_diff($ours, $ran));
        });
    }

    /**
     * Whether the MCP package the gateway is built on is installed.
     *
     * It became a requirement of the package after sites were already running,
     * and a site whose vendor folder arrives any way other than composer —
     * a deploy of a built folder, a path checkout, a git pull — gets the code
     * without it. Every page 500s then, the admin included.
     */
    public function mcpGatewayMissing(): bool
    {
        return !class_exists(\Laravel\Mcp\Facades\Mcp::class);
    }

    /**
     * @return array<int, array{level: string, title: string, body: string, command: string}>
     */
    public function notices(): array
    {
        $notices = [];

        if ($this->mcpGatewayMissing()) {
            $notices[] = [
                'level'   => 'danger',
                'title'   => trans('vela::global.health_mcp_missing'),
                'body'    => trans('vela::global.health_mcp_missing_body'),
                'command' => 'composer update velabuild/core',
            ];
        }

        $pending = $this->pendingMigrations();
        if ($pending !== []) {
            $notices[] = [
                'level'   => 'warning',
                'title'   => trans_choice('vela::global.health_migrations_pending', count($pending)),
                'body'    => trans('vela::global.health_migrations_pending_body', ['names' => implode(', ', $pending)]),
                'command' => 'php artisan vela:update',
            ];
        }

        return $notices;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
