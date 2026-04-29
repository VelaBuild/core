<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Services\AiSettingsService;

/**
 * Web search via whichever AI provider the site already has a key for.
 * Tries each configured provider in order. No extra API keys required.
 *
 *   1. Gemini   → separate `generateContent` call with `google_search`.
 *   2. Anthropic→ separate `messages` call with `web_search_20250305`.
 *   3. OpenAI   → separate Responses API call with `web_search_preview`.
 *   4. Brave / Tavily / Serper → only if those keys are set in .env.
 *
 * Returns {success, provider, query, summary?, results: [{title, url, description?}]}.
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
        $errors = [];

        try {
            if ($settings->hasApiKey('gemini')) {
                $r = $this->searchGemini($query, $count, $settings->getApiKey('gemini'));
                if (empty($r['error'])) return $r;
                $errors[] = 'gemini: ' . $r['error'];
            }
            if ($settings->hasApiKey('anthropic')) {
                $r = $this->searchClaude($query, $count, $settings->getApiKey('anthropic'));
                if (empty($r['error'])) return $r;
                $errors[] = 'anthropic: ' . $r['error'];
            }
            if ($settings->hasApiKey('openai')) {
                $r = $this->searchOpenAi($query, $count, $settings->getApiKey('openai'));
                if (empty($r['error'])) return $r;
                $errors[] = 'openai: ' . $r['error'];
            }
            if ($key = env('BRAVE_SEARCH_API_KEY')) {
                $r = $this->searchBrave($query, $count, $key);
                if (empty($r['error'])) return $r;
                $errors[] = 'brave: ' . $r['error'];
            }
            if ($key = env('TAVILY_API_KEY')) {
                $r = $this->searchTavily($query, $count, $key);
                if (empty($r['error'])) return $r;
                $errors[] = 'tavily: ' . $r['error'];
            }
            if ($key = env('SERPER_API_KEY')) {
                $r = $this->searchSerper($query, $count, $key);
                if (empty($r['error'])) return $r;
                $errors[] = 'serper: ' . $r['error'];
            }
        } catch (\Throwable $e) {
            Log::error('WebSearchTool exception', ['error' => $e->getMessage()]);
            return ['error' => 'Web search failed: ' . $e->getMessage()];
        }

        if (!empty($errors)) {
            return ['error' => 'All web search backends errored: ' . implode(' | ', $errors)];
        }
        return ['error' => 'No AI provider key is configured to power web search. Add an OpenAI / Anthropic / Gemini key in admin → Settings → AI.'];
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

    // ── OpenAI Responses API web_search_preview ─────────────────────────────

    private function searchOpenAi(string $query, int $count, string $apiKey): array
    {
        // Responses API has built-in web_search_preview. Chat Completions
        // doesn't, which is why we call /v1/responses here instead of going
        // through OpenAiTextService.
        $resp = Http::timeout(self::TIMEOUT)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->post('https://api.openai.com/v1/responses', [
                'model' => 'gpt-4o',
                'input' => "Search the web for: {$query}\n\nReturn the {$count} most relevant results with title, URL, and a brief summary of each.",
                'tools' => [['type' => 'web_search_preview']],
            ]);

        if (!$resp->successful()) {
            return ['error' => 'OpenAI web_search_preview HTTP ' . $resp->status() . ': ' . $resp->body()];
        }

        $data = $resp->json();
        $summary = '';
        $results = [];

        foreach (($data['output'] ?? []) as $item) {
            $type = $item['type'] ?? '';
            if ($type === 'message') {
                foreach (($item['content'] ?? []) as $part) {
                    if (($part['type'] ?? '') === 'output_text') {
                        $summary .= $part['text'] ?? '';
                        foreach (($part['annotations'] ?? []) as $ann) {
                            if (($ann['type'] ?? '') === 'url_citation') {
                                $results[] = [
                                    'title'       => $ann['title'] ?? null,
                                    'url'         => $ann['url'] ?? null,
                                    'description' => null,
                                ];
                                if (count($results) >= $count) break;
                            }
                        }
                    }
                }
            }
        }

        return [
            'success'  => true,
            'provider' => 'openai',
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
