<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\Tools\CloudflareService;

/**
 * Clear every page of this site from the CDN. For when the site's content
 * changed outside the admin (a restore, an import, a demo reset), so no
 * save triggered the usual purge.
 */
class CdnPurge extends Command
{
    protected $signature = 'vela:cdn-purge';

    protected $description = "Clear this site's pages from the CDN (Cloudflare)";

    public function handle(CloudflareService $cloudflare): int
    {
        if (!$cloudflare->isConfigured()) {
            $this->line('No CDN configured; nothing to purge.');

            return self::SUCCESS;
        }
        $result = $cloudflare->purgeByTags(['site']);
        // Through the gateway each batch reports on its own.
        $failed = array_filter($result['batches'] ?? [], fn ($b) => ($b['success'] ?? false) !== true);
        if (($result['success'] ?? false) !== true || $failed) {
            $result = $failed ? array_values($failed)[0] : $result;
            $this->error('CDN purge failed: ' . json_encode($result['errors'] ?? $result['error'] ?? $result));

            return self::FAILURE;
        }
        $this->info('CDN purged.');

        return self::SUCCESS;
    }
}
