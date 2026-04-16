<?php

namespace VelaBuild\Core\Services\Tools;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Services\ToolSettingsService;

class SearchConsoleService
{
    private const API_URL = 'https://www.googleapis.com/webmasters/v3';
    private const SEARCH_ANALYTICS_URL = 'https://searchconsole.googleapis.com/v1';

    public function __construct(
        private ToolSettingsService $settings,
        private GoogleAnalyticsService $gaService
    ) {}

    /**
     * Get search analytics report: top queries, pages, clicks, impressions, CTR, position.
     * Uses same service account as GA4.
     */
    public function getSearchAnalytics(string $dateRange = '28daysAgo'): ?array
    {
        $siteUrl = $this->settings->get('gsc_site_url');
        if (!$siteUrl) {
            return null;
        }

        $cacheKey = "tool_gsc_report_{$dateRange}_" . md5($siteUrl);

        return Cache::remember($cacheKey, 3600, function () use ($siteUrl, $dateRange) {
            $token = $this->getAccessToken();
            if (!$token) {
                return null;
            }

            $startDate = date('Y-m-d', strtotime($dateRange));
            $endDate = date('Y-m-d');

            $data = [];

            // Top queries
            $data['queries'] = $this->searchAnalyticsQuery($token, $siteUrl, $startDate, $endDate, ['query'], 20);

            // Top pages
            $data['pages'] = $this->searchAnalyticsQuery($token, $siteUrl, $startDate, $endDate, ['page'], 20);

            // Summary totals (no dimensions)
            $data['totals'] = $this->searchAnalyticsQuery($token, $siteUrl, $startDate, $endDate, [], 1);

            return $data;
        });
    }

    private function searchAnalyticsQuery(string $token, string $siteUrl, string $startDate, string $endDate, array $dimensions, int $rowLimit): ?array
    {
        $body = [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'rowLimit' => $rowLimit,
        ];

        if (!empty($dimensions)) {
            $body['dimensions'] = $dimensions;
        }

        $encodedUrl = urlencode($siteUrl);
        $response = Http::timeout(30)
            ->withToken($token)
            ->post(self::SEARCH_ANALYTICS_URL . "/sites/{$encodedUrl}/searchAnalytics/query", $body);

        if (!$response->successful()) {
            Log::error('Search Console query failed', ['response' => $response->json()]);
            return null;
        }

        return $response->json();
    }

    /**
     * Reuse GA4 service account token (same credentials, different scope).
     * The GA service account needs Search Console API access too.
     */
    private function getAccessToken(): ?string
    {
        $keyJson = $this->settings->get('ga_service_account_key');
        if (!$keyJson) {
            return null;
        }

        $cacheKey = 'tool_gsc_access_token';
        return Cache::remember($cacheKey, 3300, function () use ($keyJson) {
            $key = json_decode($keyJson, true);
            if (!$key || !isset($key['client_email'], $key['private_key'])) {
                return null;
            }

            $now = time();
            $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claim = base64url_encode(json_encode([
                'iss' => $key['client_email'],
                'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ]));

            $signature = '';
            openssl_sign("{$header}.{$claim}", $signature, $key['private_key'], OPENSSL_ALGO_SHA256);
            $jwt = "{$header}.{$claim}." . base64url_encode($signature);

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (!$response->successful()) {
                Log::error('GSC token exchange failed', ['response' => $response->json()]);
                return null;
            }

            return $response->json()['access_token'] ?? null;
        });
    }

    public function isConfigured(): bool
    {
        return $this->settings->hasKey('gsc_site_url') && $this->settings->hasKey('ga_service_account_key');
    }
}
