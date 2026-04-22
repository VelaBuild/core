<?php

namespace VelaBuild\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Services\Tools\CloudflareService;
use VelaBuild\Core\Services\ToolSettingsService;

class PurgeCloudflareCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 30;
    public $tries = 3;
    public $backoff = [5, 15];

    /**
     * @param array $urls  URLs to purge. Kept for back-compat with callers
     *                     that already exist.
     * @param array $tags  Cache-Tag values to purge. Preferred for CMS
     *                     content mutations because one tag can wipe a
     *                     whole cohort of URLs at once.
     *
     * Either list — or both — may be provided. Empty both = purge
     * everything when cf_purge_mode = full, no-op otherwise.
     */
    public function __construct(
        private array $urls = [],
        private array $tags = [],
    ) {}

    public static function purgeTags(array $tags): self
    {
        return new self([], $tags);
    }

    public static function purgeUrls(array $urls): self
    {
        return new self($urls, []);
    }

    public function handle(CloudflareService $cloudflare, ToolSettingsService $settings): void
    {
        if (!$cloudflare->isConfigured()) {
            return;
        }

        $purgeMode = $settings->get('cf_purge_mode', 'smart');

        // Full purge wins when configured.
        if ($purgeMode === 'full' || (empty($this->urls) && empty($this->tags))) {
            if (empty($this->urls) && empty($this->tags)) {
                // No work at all — don't purge everything by accident.
                return;
            }
            $cloudflare->purgeAll();
            return;
        }

        if (!empty($this->tags)) {
            $cloudflare->purgeByTags($this->tags);
        }
        if (!empty($this->urls)) {
            $cloudflare->purgeUrls($this->urls);
        }
    }

    /**
     * Failure must never block content publishing.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Cloudflare purge job failed', [
            'urls'  => $this->urls,
            'tags'  => $this->tags,
            'error' => $exception->getMessage(),
        ]);

        // Update tool status to 'error'
        $settings = app(ToolSettingsService::class);
        $settings->set('cf_last_error', $exception->getMessage());
    }
}
