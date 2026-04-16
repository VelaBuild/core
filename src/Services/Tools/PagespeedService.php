<?php

namespace VelaBuild\Core\Services\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Services\ToolSettingsService;

class PagespeedService
{
    private const API_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public function __construct(
        private ToolSettingsService $settings
    ) {}

    /**
     * Run PageSpeed analysis on a URL. Returns parsed scores + raw data.
     */
    public function analyze(string $url): ?array
    {
        $params = ['url' => $url, 'strategy' => 'mobile'];

        $apiKey = $this->settings->get('pagespeed_api_key');
        if ($apiKey) {
            $params['key'] = $apiKey;
        }

        $response = Http::timeout(120)->get(self::API_URL, $params);

        if (!$response->successful()) {
            Log::error('PageSpeed analysis failed', ['url' => $url, 'status' => $response->status()]);
            return null;
        }

        $data = $response->json();
        $categories = $data['lighthouseResult']['categories'] ?? [];

        return [
            'url' => $url,
            'performance_score' => isset($categories['performance']['score']) ? (int) round($categories['performance']['score'] * 100) : null,
            'accessibility_score' => isset($categories['accessibility']['score']) ? (int) round($categories['accessibility']['score'] * 100) : null,
            'seo_score' => isset($categories['seo']['score']) ? (int) round($categories['seo']['score'] * 100) : null,
            'best_practices_score' => isset($categories['best-practices']['score']) ? (int) round($categories['best-practices']['score'] * 100) : null,
            'raw_data' => $data,
        ];
    }
}
