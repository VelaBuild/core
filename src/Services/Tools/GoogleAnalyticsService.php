<?php

namespace VelaBuild\Core\Services\Tools;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Services\ToolSettingsService;

class GoogleAnalyticsService
{
    private const DATA_API_URL = 'https://analyticsdata.googleapis.com/v1beta';
    private const ADMIN_API_URL = 'https://analyticsadmin.googleapis.com/v1beta';

    public function __construct(
        private ToolSettingsService $settings
    ) {}

    /**
     * Get a GA4 report for the given date range.
     * $dateRange: '7daysAgo', '30daysAgo', '90daysAgo'
     *
     * Returns data for stat cards and charts:
     * - sessions, pageviews, bounce_rate, active_users
     * - top_pages (top 10)
     * - traffic_sources
     * - devices
     * - countries
     */
    public function getReport(string $dateRange = '30daysAgo'): ?array
    {
        $propertyId = $this->settings->get('ga_property_id');
        if (!$propertyId) {
            return null;
        }

        $cacheKey = "tool_ga_report_{$propertyId}_{$dateRange}";

        return Cache::remember($cacheKey, 3600, function () use ($propertyId, $dateRange) {
            $token = $this->getAccessToken();
            if (!$token) {
                return null;
            }

            $data = [];

            // 1. Core metrics (sessions, pageviews, bounce rate, active users)
            $data['metrics'] = $this->runReport($token, $propertyId, $dateRange, [
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'bounceRate'],
                    ['name' => 'activeUsers'],
                ],
            ]);

            // 2. Top 10 pages
            $data['top_pages'] = $this->runReport($token, $propertyId, $dateRange, [
                'dimensions' => [['name' => 'pagePath']],
                'metrics' => [['name' => 'screenPageViews'], ['name' => 'sessions']],
                'limit' => 10,
                'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
            ]);

            // 3. Traffic sources
            $data['traffic_sources'] = $this->runReport($token, $propertyId, $dateRange, [
                'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
                'metrics' => [['name' => 'sessions']],
                'limit' => 10,
            ]);

            // 4. Devices
            $data['devices'] = $this->runReport($token, $propertyId, $dateRange, [
                'dimensions' => [['name' => 'deviceCategory']],
                'metrics' => [['name' => 'sessions']],
            ]);

            // 5. Countries
            $data['countries'] = $this->runReport($token, $propertyId, $dateRange, [
                'dimensions' => [['name' => 'country']],
                'metrics' => [['name' => 'sessions']],
                'limit' => 10,
                'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            ]);

            return $data;
        });
    }

    /**
     * Check if Enhanced Measurement is active for the property.
     */
    public function getEnhancedMeasurementStatus(): ?array
    {
        $propertyId = $this->settings->get('ga_property_id');
        if (!$propertyId) {
            return null;
        }

        $cacheKey = "tool_ga_em_status_{$propertyId}";

        return Cache::remember($cacheKey, 3600, function () use ($propertyId) {
            $token = $this->getAccessToken();
            if (!$token) {
                return null;
            }

            // List data streams to find the web stream
            $response = Http::timeout(30)
                ->withToken($token)
                ->get(self::ADMIN_API_URL . "/properties/{$propertyId}/dataStreams");

            if (!$response->successful()) {
                Log::error('GA4 data streams fetch failed', ['response' => $response->json()]);
                return null;
            }

            $streams = $response->json()['dataStreams'] ?? [];
            $webStream = collect($streams)->first(fn($s) => ($s['type'] ?? '') === 'WEB_DATA_STREAM');

            if (!$webStream) {
                return ['active' => false, 'message' => 'No web data stream found'];
            }

            // Get enhanced measurement settings
            $streamName = $webStream['name'];
            $emResponse = Http::timeout(30)
                ->withToken($token)
                ->get(self::ADMIN_API_URL . "/{$streamName}/enhancedMeasurementSettings");

            if (!$emResponse->successful()) {
                return ['active' => false, 'message' => 'Could not fetch EM settings'];
            }

            $emSettings = $emResponse->json();
            return [
                'active' => ($emSettings['streamEnabled'] ?? false),
                'page_views' => $emSettings['pageViewsEnabled'] ?? false,
                'scrolls' => $emSettings['scrollsEnabled'] ?? false,
                'outbound_clicks' => $emSettings['outboundClicksEnabled'] ?? false,
                'site_search' => $emSettings['siteSearchEnabled'] ?? false,
                'form_interactions' => $emSettings['formInteractionsEnabled'] ?? false,
                'file_downloads' => $emSettings['fileDownloadsEnabled'] ?? false,
            ];
        });
    }

    /**
     * Run a GA4 Data API report.
     */
    private function runReport(string $token, string $propertyId, string $startDate, array $body): ?array
    {
        $payload = array_merge([
            'dateRanges' => [['startDate' => $startDate, 'endDate' => 'today']],
        ], $body);

        $response = Http::timeout(120)
            ->withToken($token)
            ->post(self::DATA_API_URL . "/properties/{$propertyId}:runReport", $payload);

        if (!$response->successful()) {
            Log::error('GA4 report failed', ['property' => $propertyId, 'response' => $response->json()]);
            return null;
        }

        return $response->json();
    }

    /**
     * Get OAuth2 access token from service account key.
     * Uses Google's JWT-based service account auth.
     */
    private function getAccessToken(): ?string
    {
        $keyJson = $this->settings->get('ga_service_account_key');
        if (!$keyJson) {
            return null;
        }

        $cacheKey = 'tool_ga_access_token';
        return Cache::remember($cacheKey, 3300, function () use ($keyJson) {
            $key = json_decode($keyJson, true);
            if (!$key || !isset($key['client_email'], $key['private_key'])) {
                Log::error('GA4 service account key is invalid');
                return null;
            }

            $now = time();
            $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claim = base64url_encode(json_encode([
                'iss' => $key['client_email'],
                'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/analytics.edit',
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
                Log::error('GA4 token exchange failed', ['response' => $response->json()]);
                return null;
            }

            return $response->json()['access_token'] ?? null;
        });
    }

    public function isConfigured(): bool
    {
        return $this->settings->hasKey('ga_measurement_id');
    }

    public function hasReportingAccess(): bool
    {
        return $this->settings->hasKey('ga_property_id') && $this->settings->hasKey('ga_service_account_key');
    }
}
