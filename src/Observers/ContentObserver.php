<?php

namespace VelaBuild\Core\Observers;

use VelaBuild\Core\Jobs\GenerateStaticFilesJob;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\VelaConfig;

class ContentObserver
{
    public function saved(Content $content): void
    {
        if (! config('vela.static.enabled', true)) {
            return;
        }
        if ($content->type !== 'post') {
            return;
        }

        $oldSlug = $content->getOriginal('slug');
        $oldStatus = $content->getOriginal('status');

        // Clean up old slug directory if slug changed
        if ($oldSlug && $oldSlug !== $content->slug) {
            $generator = app(\VelaBuild\Core\Services\StaticSiteGenerator::class);
            $generator->removeAll('posts', $oldSlug);
        }

        if ($content->status === 'published') {
            GenerateStaticFilesJob::dispatch('content', $content->id);
            GenerateStaticFilesJob::dispatch('home');
            GenerateStaticFilesJob::dispatch('posts_index');
            foreach ($content->categories as $category) {
                GenerateStaticFilesJob::dispatch('category', $category->id);
            }
        } elseif ($oldStatus === 'published' && $content->status !== 'published') {
            $generator = app(\VelaBuild\Core\Services\StaticSiteGenerator::class);
            $generator->removeHtml('posts', $content->slug);
            GenerateStaticFilesJob::dispatch('home');
            GenerateStaticFilesJob::dispatch('posts_index');
        } else {
            $generator = app(\VelaBuild\Core\Services\StaticSiteGenerator::class);
            $generator->writeContentConfigJson($content);
        }

        // Bump service worker cache version so returning visitors get fresh content
        $current = (int) (VelaConfig::where('key', 'sw_version')->value('value') ?? 0);
        VelaConfig::updateOrCreate(['key' => 'sw_version'], ['value' => (string) ($current + 1)]);

        // Cloudflare auto-purge
        try {
            $toolSettings = app(\VelaBuild\Core\Services\ToolSettingsService::class);
            if ($toolSettings->hasKey('cf_api_token') && $content->status === 'published') {
                $urls = [url("/posts/{$content->slug}")];
                \VelaBuild\Core\Jobs\PurgeCloudflareCacheJob::dispatch($urls)
                    ->delay(now()->addSeconds(30));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Cloudflare auto-purge dispatch failed', ['error' => $e->getMessage()]);
        }
    }

    public function deleted(Content $content): void
    {
        if (! config('vela.static.enabled', true)) {
            return;
        }
        if ($content->type !== 'post') {
            return;
        }

        $generator = app(\VelaBuild\Core\Services\StaticSiteGenerator::class);
        $generator->removeAll('posts', $content->slug);
        GenerateStaticFilesJob::dispatch('home');
        GenerateStaticFilesJob::dispatch('posts_index');

        // Bump service worker cache version so returning visitors get fresh content
        $current = (int) (VelaConfig::where('key', 'sw_version')->value('value') ?? 0);
        VelaConfig::updateOrCreate(['key' => 'sw_version'], ['value' => (string) ($current + 1)]);

        // Cloudflare auto-purge
        try {
            $toolSettings = app(\VelaBuild\Core\Services\ToolSettingsService::class);
            if ($toolSettings->hasKey('cf_api_token')) {
                \VelaBuild\Core\Jobs\PurgeCloudflareCacheJob::dispatch([url("/posts/{$content->slug}")])
                    ->delay(now()->addSeconds(30));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Cloudflare auto-purge dispatch failed', ['error' => $e->getMessage()]);
        }
    }
}
