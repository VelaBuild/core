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
     * @param array $urls  URLs to purge. Empty array = purge all.
     */
    public function __construct(
        private array $urls = []
    ) {}

    public function handle(CloudflareService $cloudflare, ToolSettingsService $settings): void
    {
        if (!$cloudflare->isConfigured()) {
            return;
        }

        $purgeMode = $settings->get('cf_purge_mode', 'smart');

        if (empty($this->urls) || $purgeMode === 'full') {
            $cloudflare->purgeAll();
        } else {
            $cloudflare->purgeUrls($this->urls);
        }
    }

    /**
     * Failure must never block content publishing.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Cloudflare purge job failed', [
            'urls' => $this->urls,
            'error' => $exception->getMessage(),
        ]);

        // Update tool status to 'error'
        $settings = app(ToolSettingsService::class);
        $settings->set('cf_last_error', $exception->getMessage());
    }
}
