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
            // Anthropic requires the system prompt as a top-level `system`
            // param — passing it as a role:system entry inside `messages`
            // 400s with "messages.0: use the top-level 'system' parameter".
            // Callers share one messages array across providers (OpenAI/Gemini
            // accept system inline), so lift any system entries out here.
            $system = null;
            $messages = array_values(array_filter($messages, function ($m) use (&$system) {
                if (($m['role'] ?? null) !== 'system') {
                    return true;
                }
                $content = $m['content'] ?? '';
                $text = is_array($content)
                    ? implode("\n", array_filter(array_map(fn ($b) => $b['text'] ?? null, $content)))
                    : (string) $content;
                $system = $system === null ? $text : $system . "\n" . $text;
                return false;
            }));

            // Callers keep one OpenAI-shaped history across providers
            // (assistant turns carry `tool_calls`, results come back as
            // role:tool messages). Anthropic only accepts user/assistant
            // roles with tool_use / tool_result *content blocks*, so translate
            // before sending — otherwise it 400s on `role "tool"`.
            $messages = $this->toAnthropicMessages($messages);
            $messages = $this->normalizeVisionMessages($messages);
            $body = [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'messages' => $messages,
            ];
            if ($system !== null && $system !== '') {
                $body['system'] = $system;
            }

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
    /**
     * Translate the shared OpenAI-shaped message history into Anthropic's
     * format: assistant `tool_calls` become `tool_use` content blocks, and
     * role:tool results become `tool_result` blocks folded into a single
     * user turn (Anthropic requires all results for one assistant turn in one
     * user message). Plain text user/assistant messages pass through.
     */
    private function toAnthropicMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $m) {
            $role = $m['role'] ?? 'user';

            if ($role === 'tool') {
                $content = $m['content'] ?? '';
                $block = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $m['tool_call_id'] ?? ($m['tool_use_id'] ?? null),
                    'content'     => is_string($content) ? $content : json_encode($content),
                ];

                // Fold consecutive tool results into the preceding user turn.
                $lastIdx = count($out) - 1;
                if ($lastIdx >= 0
                    && ($out[$lastIdx]['role'] ?? null) === 'user'
                    && is_array($out[$lastIdx]['content'] ?? null)
                    && (($out[$lastIdx]['content'][0]['type'] ?? null) === 'tool_result')
                ) {
                    $out[$lastIdx]['content'][] = $block;
                } else {
                    $out[] = ['role' => 'user', 'content' => [$block]];
                }
                continue;
            }

            if ($role === 'assistant' && !empty($m['tool_calls'])) {
                $content = [];
                if (!empty($m['content'])) {
                    $content[] = [
                        'type' => 'text',
                        'text' => is_string($m['content']) ? $m['content'] : json_encode($m['content']),
                    ];
                }
                foreach ($m['tool_calls'] as $tc) {
                    // Accept either OpenAI shape ({id, function:{name, arguments}})
                    // or our internal shape ({id, name, arguments}).
                    $name = $tc['function']['name'] ?? $tc['name'] ?? '';
                    $args = $tc['function']['arguments'] ?? $tc['arguments'] ?? [];
                    if (is_string($args)) {
                        $decoded = json_decode($args, true);
                        $args = is_array($decoded) ? $decoded : [];
                    }
                    $content[] = [
                        'type'  => 'tool_use',
                        'id'    => $tc['id'] ?? null,
                        'name'  => $name,
                        'input' => empty($args) ? new \stdClass : $args,
                    ];
                }
                $out[] = ['role' => 'assistant', 'content' => $content];
                continue;
            }

            // Plain user/assistant message — keep content as-is (string, or an
            // array of vision blocks handled by normalizeVisionMessages).
            $out[] = ['role' => $role, 'content' => $m['content'] ?? ''];
        }

        return $out;
    }

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
