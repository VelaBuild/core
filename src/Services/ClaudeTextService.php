<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Contracts\AiTextProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use VelaBuild\Core\Services\AiSettingsService;
class ClaudeTextService implements AiTextProvider
{
    private ?string $apiKey;
    private string $baseUrl = 'https://api.anthropic.com/v1/messages';
    private string $model = 'claude-sonnet-4-20250514';

    public function __construct()
    {
        $this->apiKey = app(AiSettingsService::class)->getApiKey('anthropic');
    }

    public function generateText(string $prompt, int $maxTokens = 1000, float $temperature = 0.7): ?string
    {
        if (!$this->apiKey) {
            Log::warning('Vela: Anthropic API key not configured');
            return null;
        }

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ])->post($this->baseUrl, [
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Claude text generation successful', [
                    'prompt' => substr($prompt, 0, 100) . '...',
                    'model' => $this->model,
                    'max_tokens' => $maxTokens,
                ]);
                return $data['content'][0]['text'] ?? null;
            } else {
                Log::error('Claude text generation failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'prompt' => substr($prompt, 0, 100) . '...',
                    'model' => $this->model,
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Claude text generation exception', [
                'message' => $e->getMessage(),
                'prompt' => substr($prompt, 0, 100) . '...',
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'exception_type' => get_class($e),
            ]);
            return null;
        }
    }

    public function supportsVision(): bool
    {
        return true;
    }

    public function chat(array $messages, array $tools = [], int $maxTokens = 4096): ?array
    {
        if (!$this->apiKey) {
            Log::warning('Vela: Anthropic API key not configured');
            return null;
        }

        try {
            $messages = $this->normalizeVisionMessages($messages);
            $body = [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'messages' => $messages,
            ];

            if (!empty($tools)) {
                $body['tools'] = $tools;
            }

            // Native Anthropic web search — server-side tool, Claude handles
            // the search/fetch round-trip itself and inserts citations into
            // the response. Available on Claude 3.7+ / Claude 4 models. DB
            // toggle in admin AI Settings overrides the config default.
            if (app(AiSettingsService::class)->getStatus()['native_search']) {
                $body['tools'] = $body['tools'] ?? [];
                // The unified custom `web_search` tool (from ChatToolRegistry)
                // and the native server-side tool below share the name
                // "web_search" — sending both trips Anthropic's "Tool names
                // must be unique" 400. With native search on, Claude runs the
                // search server-side, so drop the custom one in its favour.
                $body['tools'] = array_values(array_filter($body['tools'], function ($t) {
                    return ($t['name'] ?? null) !== 'web_search';
                }));
                $body['tools'][] = [
                    'type'     => 'web_search_20250305',
                    'name'     => 'web_search',
                    'max_uses' => (int) config('vela.ai.chat.native_search_max_uses', 5),
                ];
            }

            // Backstop: Anthropic rejects the whole request with a 400
            // ("tools: Tool names must be unique") if any two tools share a
            // name. As the toolset grows (registry tools + native injections),
            // a stray duplicate must never take the entire chat down — drop
            // any later tool whose name was already seen, keeping the first.
            if (!empty($body['tools'])) {
                $seen = [];
                $body['tools'] = array_values(array_filter($body['tools'], function ($t) use (&$seen) {
                    $name = $t['name'] ?? null;
                    if ($name === null) {
                        return true;
                    }
                    if (isset($seen[$name])) {
                        Log::warning('Vela: dropped duplicate Claude tool name', ['name' => $name]);
                        return false;
                    }
                    $seen[$name] = true;
                    return true;
                }));
            }

            $response = Http::timeout(120)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ])->post($this->baseUrl, $body);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Claude chat successful', [
                    'model' => $this->model,
                    'stop_reason' => $data['stop_reason'] ?? null,
                ]);

                // Normalize response
                $content = null;
                $toolCalls = null;

                foreach ($data['content'] ?? [] as $block) {
                    if ($block['type'] === 'text') {
                        $content = $block['text'];
                    } elseif ($block['type'] === 'tool_use') {
                        $toolCalls[] = [
                            'id' => $block['id'],
                            'name' => $block['name'],
                            'arguments' => $block['input'],
                        ];
                    }
                }

                return [
                    'content' => $content,
                    'tool_calls' => $toolCalls,
                    'usage' => [
                        'input' => $data['usage']['input_tokens'] ?? 0,
                        'output' => $data['usage']['output_tokens'] ?? 0,
                    ],
                ];
            } else {
                Log::error('Claude chat failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'model' => $this->model,
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Claude chat exception', [
                'message' => $e->getMessage(),
                'model' => $this->model,
                'exception_type' => get_class($e),
            ]);
            return null;
        }
    }

    /**
     * Normalize vision messages to Anthropic format.
     * Converts unified image blocks to Anthropic's image source format.
     */
    private function normalizeVisionMessages(array $messages): array
    {
        return array_map(function ($message) {
            if (!is_array($message['content'] ?? null)) {
                return $message;
            }

            $message['content'] = array_map(function ($block) {
                if (($block['type'] ?? '') === 'image') {
                    return [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $block['media_type'] ?? 'image/png',
                            'data' => $block['source'],
                        ],
                    ];
                }
                return $block;
            }, $message['content']);

            return $message;
        }, $messages);
    }
}
