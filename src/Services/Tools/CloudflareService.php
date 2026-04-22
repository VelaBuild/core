<?php

namespace VelaBuild\Core\Services\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Services\ToolSettingsService;

class CloudflareService
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private ToolSettingsService $settings
    ) {}

    /**
     * Purge specific URLs from cache.
     */
    public function purgeUrls(array $urls): array
    {
        $zoneId = $this->settings->get('cf_zone_id');
        $token = $this->settings->get('cf_api_token');

        $response = Http::timeout(30)
            ->withToken($token)
            ->post(self::BASE_URL . "/zones/{$zoneId}/purge_cache", [
                'files' => $urls,
            ]);

        $result = $response->json();
        if (!$response->successful()) {
            Log::error('Cloudflare purge URLs failed', ['response' => $result, 'urls' => $urls]);
        } else {
            Log::info('Cloudflare purge URLs success', ['count' => count($urls)]);
        }

        return $result;
    }

    /**
     * Purge by Cache-Tag values.
     *
     * Cloudflare has been expanding Cache-Tag purge availability across
     * plan tiers, and the zone's dashboard is the authoritative signal for
     * what's enabled right now. We just call the API — if a zone can't use
     * it, the error body surfaces in the log and the caller can fall back
     * to URL-based purging. This method never throws on a non-2xx so the
     * URL path stays operational alongside.
     *
     * Cloudflare caps at 30 tags per request here, so we chunk.
     *
     * @param array<int, string> $tags
     */
    public function purgeByTags(array $tags): array
    {
        $tags = array_values(array_unique(array_filter($tags)));
        if (empty($tags)) {
            return ['success' => true, 'noop' => true];
        }

        $results = [];
        foreach (array_chunk($tags, 30) as $chunk) {
            $zoneId = $this->settings->get('cf_zone_id');
            $token  = $this->settings->get('cf_api_token');

            $response = Http::timeout(30)
                ->withToken($token)
                ->post(self::BASE_URL . "/zones/{$zoneId}/purge_cache", [
                    'tags' => array_values($chunk),
                ]);

            $json = $response->json();
            if (!$response->successful()) {
                Log::warning('Cloudflare purge-by-tags non-2xx', [
                    'status' => $response->status(),
                    'tags'   => $chunk,
                    'errors' => $json['errors'] ?? null,
                ]);
            } else {
                Log::info('Cloudflare purge-by-tags success', ['count' => count($chunk)]);
            }
            $results[] = $json;
        }
        return ['success' => true, 'batches' => $results];
    }

    /**
     * Purge entire zone cache.
     */
    public function purgeAll(): array
    {
        $zoneId = $this->settings->get('cf_zone_id');
        $token = $this->settings->get('cf_api_token');

        $response = Http::timeout(30)
            ->withToken($token)
            ->post(self::BASE_URL . "/zones/{$zoneId}/purge_cache", [
                'purge_everything' => true,
            ]);

        $result = $response->json();
        if (!$response->successful()) {
            Log::error('Cloudflare purge all failed', ['response' => $result]);
        } else {
            Log::info('Cloudflare purge all success');
        }

        return $result;
    }

    /**
     * Get zone details (status, SSL, caching).
     */
    public function getZoneStatus(): array
    {
        $zoneId = $this->settings->get('cf_zone_id');
        $token = $this->settings->get('cf_api_token');

        $response = Http::timeout(30)
            ->withToken($token)
            ->get(self::BASE_URL . "/zones/{$zoneId}");

        $result = $response->json();
        if (!$response->successful()) {
            Log::error('Cloudflare zone status failed', ['response' => $result]);
            return ['success' => false, 'errors' => $result['errors'] ?? []];
        }

        return $result;
    }

    /**
     * Get page rules for the zone.
     */
    public function getPageRules(): array
    {
        $zoneId = $this->settings->get('cf_zone_id');
        $token = $this->settings->get('cf_api_token');

        $response = Http::timeout(30)
            ->withToken($token)
            ->get(self::BASE_URL . "/zones/{$zoneId}/pagerules");

        $result = $response->json();
        if (!$response->successful()) {
            Log::error('Cloudflare page rules failed', ['response' => $result]);
        }

        return $result;
    }

    /**
     * Get SSL/TLS settings.
     */
    public function getSslSetting(): array
    {
        $zoneId = $this->settings->get('cf_zone_id');
        $token = $this->settings->get('cf_api_token');

        $response = Http::timeout(30)
            ->withToken($token)
            ->get(self::BASE_URL . "/zones/{$zoneId}/settings/ssl");

        return $response->json();
    }

    /**
     * Get cache level setting.
     */
    public function getCacheSetting(): array
    {
        $zoneId = $this->settings->get('cf_zone_id');
        $token = $this->settings->get('cf_api_token');

        $response = Http::timeout(30)
            ->withToken($token)
            ->get(self::BASE_URL . "/zones/{$zoneId}/settings/cache_level");

        return $response->json();
    }

    /**
     * Verify the zone ID matches the site domain.
     */
    public function verifyZone(): bool
    {
        $result = $this->getZoneStatus();
        return ($result['success'] ?? false) === true;
    }

    /**
     * Check if Cloudflare is configured.
     */
    public function isConfigured(): bool
    {
        return $this->settings->hasKey('cf_api_token') && $this->settings->hasKey('cf_zone_id');
    }
}
