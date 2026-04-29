<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\AiSettingsService;

/**
 * Web search via the configured AI provider's native search capability —
 * no extra API key needed. Picks the first available provider:
 *
 *   1. Gemini → separate API call with `google_search` enabled (can't be
 *      combined with function calling in the same request, so we run a
 *      dedicated grounding call here).
 *   2. Anthropic → separate API call with `web_search_20250305` server tool.
 *   3. Brave / Tavily / Serper → only if explicitly configured.
 *
 * Returns a normalized {provider, query, results: [{title, url, description}]}
 * shape regardless of which backend ran.
 */
class WebSearchTool extends BaseTool
{
    private const TIMEOUT = 30;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $query = trim((string) ($parameters['query'] ?? ''));
        if ($query === '') {
            return ['error' => 'query parameter is required'];
        }
        $count = max(1, min(10, (int) ($parameters['count'] ?? 5)));

        $settings = app(AiSettingsService::class);

        try {
            if ($settings->hasApiKey('gemini')) {
                return $this->searchGemini($query, $count, $settings->getApiKey('gemini'));
            }
            if ($settings->hasApiKey('anthropic')) {
                return $this->searchClaude($query, $count, $settings->getApiKey('anthropic'));
            }
            if ($key = env('BRAVE_SEARCH_API_KEY')) {
                return $this->searchBrave($query, $count, $key);
            }
            if ($key = env('TAVILY_API_KEY')) {
                return $this->searchTavily($query, $count, $key);
            }
            if ($key = env('SERPER_API_KEY')) {
                return $this->searchSerper($query, $count, $key);
            }
        } catch (\Throwable $e) {
            Log::error('WebSearchTool failed', ['error' => $e->getMessage()]);
            return ['error' => 'Web search failed: ' . $e->getMessage()];
        }

        return [
            'error' => 'No web search provider available. Configure a Gemini or Anthropic key in admin → Settings → AI, or add BRAVE_SEARCH_API_KEY / TAVILY_API_KEY / SERPER_API_KEY in .env.',
        ];
    }

    // ── Gemini google_search via grounding ──────────────────────────────────

    private function searchGemini(string $query, int $count, string $apiKey): array
    {
        $resp = Http::timeout(self::TIMEOUT)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $apiKey, [
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => "Search the web for: {$query}\n\nReturn the most relevant {$count} results with title, URL, and a one-sentence summary."]],
                ]],
                'tools' => [['google_search' => (object) []]],
            ]);

        if (!$resp->successful()) {
            return ['error' => 'Gemini google_search HTTP ' . $resp->status() . ': ' . $resp->body()];
        }

        $data = $resp->json();
        $candidate = $data['candidates'][0] ?? [];
        $summary = collect($candidate['content']['parts'] ?? [])
            ->pluck('text')
            ->filter()
            ->implode("\n");

        $results = [];
        foreach (($candidate['groundingMetadata']['groundingChunks'] ?? []) as $chunk) {
            $web = $chunk['web'] ?? null;
            if (!$web) continue;
            $results[] = [
                'title'       => $web['title'] ?? null,
                'url'         => $web['uri'] ?? null,
                'description' => null,
            ];
            if (count($results) >= $count) break;
        }

        return [
            'success'  => true,
            'provider' => 'gemini',
            'query'    => $query,
            'summary'  => $summary,
            'results'  => $results,
        ];
    }

    // ── Anthropic web_search_20250305 ───────────────────────────────────────

    private function searchClaude(string $query, int $count, string $apiKey): array
    {
        $resp = Http::timeout(self::TIMEOUT)
            ->withHeaders([
                'x-api-key'         => $apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model'      => 'claude-sonnet-4-6',
                'max_tokens' => 1024,
                'messages'   => [[
                    'role'    => 'user',
                    'content' => "Search the web for: {$query}\n\nReturn the {$count} most relevant results with title, URL, and a brief summary.",
                ]],
                'tools' => [[
                    'type'     => 'web_search_20250305',
                    'name'     => 'web_search',
                    'max_uses' => 3,
                ]],
            ]);

        if (!$resp->successful()) {
            return ['error' => 'Claude web_search HTTP ' . $resp->status() . ': ' . $resp->body()];
        }

        $data = $resp->json();
        $summary = '';
        $results = [];

        foreach (($data['content'] ?? []) as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $summary .= $block['text'] ?? '';
            } elseif ($type === 'web_search_tool_result') {
                foreach (($block['content'] ?? []) as $r) {
                    if (($r['type'] ?? '') === 'web_search_result') {
                        $results[] = [
                            'title'       => $r['title'] ?? null,
                            'url'         => $r['url'] ?? null,
                            'description' => $r['encrypted_content'] ? null : ($r['snippet'] ?? null),
                        ];
                    }
                    if (count($results) >= $count) break 2;
                }
            }
        }

        return [
            'success'  => true,
            'provider' => 'anthropic',
            'query'    => $query,
            'summary'  => trim($summary),
            'results'  => $results,
        ];
    }

    // ── Brave / Tavily / Serper fallbacks (only used when no AI key) ────────

    private function searchBrave(string $query, int $count, string $apiKey): array
    {
        $resp = Http::timeout(self::TIMEOUT)
            ->withHeaders(['Accept' => 'application/json', 'X-Subscription-Token' => $apiKey])
            ->get('https://api.search.brave.com/res/v1/web/search', ['q' => $query, 'count' => $count]);

        if (!$resp->successful()) {
            return ['error' => 'Brave HTTP ' . $resp->status() . ': ' . $resp->body()];
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
            'api_key'      => $apiKey,
            'query'        => $query,
            'max_results'  => $count,
            'search_depth' => 'basic',
        ]);
        if (!$resp->successful()) {
            return ['error' => 'Tavily HTTP ' . $resp->status() . ': ' . $resp->body()];
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
            ->withHeaders(['X-API-KEY' => $apiKey, 'Content-Type' => 'application/json'])
            ->post('https://google.serper.dev/search', ['q' => $query, 'num' => $count]);
        if (!$resp->successful()) {
            return ['error' => 'Serper HTTP ' . $resp->status() . ': ' . $resp->body()];
        }
        $results = collect($resp->json('organic') ?? [])->take($count)->map(fn ($r) => [
            'title'       => $r['title'] ?? null,
            'url'         => $r['link'] ?? null,
            'description' => $r['snippet'] ?? null,
        ])->values()->all();
        return ['success' => true, 'provider' => 'serper', 'query' => $query, 'results' => $results];
    }
}
