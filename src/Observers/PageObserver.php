<?php

namespace VelaBuild\Core\Observers;

use VelaBuild\Core\Jobs\GenerateStaticFilesJob;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaConfig;

class PageObserver
{
    public function saved(Page $page): void
    {
        if (! config('vela.static.enabled', true)) {
            return;
        }

        $oldSlug = $page->getOriginal('slug');
        $oldStatus = $page->getOriginal('status');

        // Clean up old slug directory if slug changed
        if ($oldSlug && $oldSlug !== $page->slug) {
            $generator = app(\VelaBuild\Core\Services\StaticSiteGenerator::class);
            $generator->removeAll('pages', $oldSlug);
        }

        if ($page->status === 'published') {
            // Regenerate ALL pages because navigation is baked in
            GenerateStaticFilesJob::dispatch('all');
        } elseif ($oldStatus === 'published' && $page->status !== 'published') {
            // Was published, now unpublished: remove HTML, keep config
            $generator = app(\VelaBuild\Core\Services\StaticSiteGenerator::class);
            $generator->removeHtml('pages', $page->slug);
            // Still regenerate all because nav changed
            GenerateStaticFilesJob::dispatch('all');
        } else {
            // Draft page: still write config JSON
            $generator = app(\VelaBuild\Core\Services\StaticSiteGenerator::class);
            $generator->writeConfigJson($page);
        }

        $current = (int) (VelaConfig::where('key', 'sw_version')->value('value') ?? 0);
        VelaConfig::updateOrCreate(['key' => 'sw_version'], ['value' => (string) ($current + 1)]);

        // Cloudflare auto-purge
        try {
            $toolSettings = app(\VelaBuild\Core\Services\ToolSettingsService::class);
            if ($toolSettings->hasKey('cf_api_token') && $page->status === 'published') {
                $urls = [url("/{$page->slug}")];
                \VelaBuild\Core\Jobs\PurgeCloudflareCacheJob::dispatch($urls)
                    ->delay(now()->addSeconds(30));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Cloudflare auto-purge dispatch failed', ['error' => $e->getMessage()]);
        }
    }

    public function deleted(Page $page): void
    {
        if (! config('vela.static.enabled', true)) {
            return;
        }

        $generator = app(\VelaBuild\Core\Services\StaticSiteGenerator::class);
        $generator->removeAll('pages', $page->slug);
        GenerateStaticFilesJob::dispatch('all');

        $current = (int) (VelaConfig::where('key', 'sw_version')->value('value') ?? 0);
        VelaConfig::updateOrCreate(['key' => 'sw_version'], ['value' => (string) ($current + 1)]);

        // Cloudflare auto-purge
        try {
            $toolSettings = app(\VelaBuild\Core\Services\ToolSettingsService::class);
            if ($toolSettings->hasKey('cf_api_token')) {
                \VelaBuild\Core\Jobs\PurgeCloudflareCacheJob::dispatch([url("/{$page->slug}")])
                    ->delay(now()->addSeconds(30));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Cloudflare auto-purge dispatch failed', ['error' => $e->getMessage()]);
        }
    }
}
