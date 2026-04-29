<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Models\AiActionLog;

class WebSearchTool extends BaseTool
{
    private const TIMEOUT = 15;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $query = trim((string) ($parameters['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'query parameter is required'];
        }
        $count = max(1, min(10, (int) ($parameters['count'] ?? 5)));

        $provider = $this->resolveProvider();
        if (!$provider) {
            return [
                'error' => 'No web search provider configured. Set BRAVE_SEARCH_API_KEY, TAVILY_API_KEY, or SERPER_API_KEY in .env.',
            ];
        }

        try {
            return $this->{$provider['method']}($query, $count, $provider['key']);
        } catch (\Throwable $e) {
            Log::error('WebSearchTool failed', ['provider' => $provider['name'], 'error' => $e->getMessage()]);
            return ['error' => "Web search failed via {$provider['name']}: " . $e->getMessage()];
        }
    }

    private function resolveProvider(): ?array
    {
        // First match wins. User explicitly chooses by setting only the key
        // they want to use. All three are read-only HTTPS APIs.
        if ($key = env('BRAVE_SEARCH_API_KEY')) {
            return ['name' => 'brave', 'method' => 'searchBrave', 'key' => $key];
        }
        if ($key = env('TAVILY_API_KEY')) {
            return ['name' => 'tavily', 'method' => 'searchTavily', 'key' => $key];
        }
        if ($key = env('SERPER_API_KEY')) {
            return ['name' => 'serper', 'method' => 'searchSerper', 'key' => $key];
        }
        return null;
    }

    private function searchBrave(string $query, int $count, string $apiKey): array
    {
        $resp = Http::timeout(self::TIMEOUT)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Subscription-Token' => $apiKey,
            ])
            ->get('https://api.search.brave.com/res/v1/web/search', [
                'q'     => $query,
                'count' => $count,
            ]);

        if (!$resp->successful()) {
            return ['error' => 'Brave search HTTP ' . $resp->status() . ': ' . $resp->body()];
        }

        $results = collect($resp->json('web.results') ?? [])->take($count)->map(fn ($r) => [
            'title'       => $r['title'] ?? null,
            'url'         => $r['url'] ?? null,
            'description' => $r['description'] ?? null,
        ])->values()->all();

        return ['success' => true, 'provider' => 'brave', 'query' => $query, 'results' => $results];
    }

    private function searchTavily(string $query, int $count, string $apiKey): array
    {
        $resp = Http::timeout(self::TIMEOUT)->post('https://api.tavily.com/search', [
            'api_key'        => $apiKey,
            'query'          => $query,
            'max_results'    => $count,
            'search_depth'   => 'basic',
        ]);

        if (!$resp->successful()) {
            return ['error' => 'Tavily search HTTP ' . $resp->status() . ': ' . $resp->body()];
        }

        $results = collect($resp->json('results') ?? [])->take($count)->map(fn ($r) => [
            'title'       => $r['title'] ?? null,
            'url'         => $r['url'] ?? null,
            'description' => $r['content'] ?? null,
        ])->values()->all();

        return ['success' => true, 'provider' => 'tavily', 'query' => $query, 'results' => $results];
    }

    private function searchSerper(string $query, int $count, string $apiKey): array
    {
        $resp = Http::timeout(self::TIMEOUT)
            ->withHeaders([
                'X-API-KEY'    => $apiKey,
                'Content-Type' => 'application/json',
            ])
            ->post('https://google.serper.dev/search', [
                'q'   => $query,
                'num' => $count,
            ]);

        if (!$resp->successful()) {
            return ['error' => 'Serper search HTTP ' . $resp->status() . ': ' . $resp->body()];
        }

        $results = collect($resp->json('organic') ?? [])->take($count)->map(fn ($r) => [
            'title'       => $r['title'] ?? null,
            'url'         => $r['link'] ?? null,
            'description' => $r['snippet'] ?? null,
        ])->values()->all();

        return ['success' => true, 'provider' => 'serper', 'query' => $query, 'results' => $results];
    }
}
