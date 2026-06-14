<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\ToolSettingsService;
use Illuminate\Support\Facades\Http;

class PageSpeedTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $url = $parameters['url'] ?? '';
        $strategy = $parameters['strategy'] ?? 'mobile';

        if (!$url) {
            return ['error' => 'url is required'];
        }

        $apiKey = app(ToolSettingsService::class)->get('pagespeed_api_key');

        $apiUrl = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
        $params = [
            'url' => $url,
            'strategy' => $strategy,
            'category' => ['performance', 'accessibility', 'best-practices', 'seo'],
        ];
        if ($apiKey) {
            $params['key'] = $apiKey;
        }

        try {
            $response = Http::timeout(60)->get($apiUrl, $params);
        } catch (\Throwable $e) {
            return ['error' => 'PageSpeed API request failed: ' . $e->getMessage()];
        }

        if (!$response->successful()) {
            return ['error' => 'PageSpeed API returned HTTP ' . $response->status()];
        }

        $data = $response->json();
        $lighthouse = $data['lighthouseResult'] ?? [];
        $categories = $lighthouse['categories'] ?? [];

        $scores = [];
        foreach ($categories as $key => $cat) {
            $scores[$key] = [
                'score' => round(($cat['score'] ?? 0) * 100),
                'title' => $cat['title'] ?? $key,
            ];
        }

        $audits = $lighthouse['audits'] ?? [];
        $opportunities = [];
        foreach ($audits as $id => $audit) {
            if (($audit['score'] ?? 1) < 0.9 && !empty($audit['title'])) {
                $opportunities[] = [
                    'id' => $id,
                    'title' => $audit['title'],
                    'score' => round(($audit['score'] ?? 0) * 100),
                    'description' => $audit['description'] ?? '',
                    'savings' => $audit['details']['overallSavingsMs'] ?? null,
                ];
            }
        }

        usort($opportunities, fn($a, $b) => ($a['score'] ?? 100) <=> ($b['score'] ?? 100));

        return [
            'url' => $url,
            'strategy' => $strategy,
            'scores' => $scores,
            'opportunities' => array_slice($opportunities, 0, 15),
            'loading_experience' => $data['loadingExperience']['metrics'] ?? [],
        ];
    }
}
