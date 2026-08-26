<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Services\Concerns\ReportsAiFailure;

use VelaBuild\Core\Contracts\AiTextProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use VelaBuild\Core\Services\AiSettingsService;
class ClaudeTextService implements AiTextProvider
{
    use ReportsAiFailure;

    private ?string $apiKey;
    private string $baseUrl = 'https://api.anthropic.com/v1/messages';
    private string $model;

    public function __construct()
    {
        $this->apiKey = app(AiSettingsService::class)->getApiKey('anthropic');
        // Config-driven so a model retirement is an env change, not a code edit.
        // (claude-sonnet-4-20250514 / Sonnet 4.0 was retired and now 404s; the
        // drop-in replacement is claude-sonnet-4-6.) Use exact ids — no date suffix.
        $this->model = (string) config('vela.ai.chat.anthropic_model', 'claude-sonnet-4-6');
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
                $this->recordAiFailure($response->status(), $response->body());
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
            $this->recordAiException($e);
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

            // Final guard: Anthropic 400s the entire request when
            // input + max_tokens exceeds the context window. Estimate the
            // input from the serialized body and shrink max_tokens to fit so a
            // large conversation degrades (shorter answer) instead of failing.
            $contextLimit = (int) config('vela.ai.chat.context_limit', 200000);
            $estInput = (int) ceil(mb_strlen(json_encode($body['messages']) . ($body['system'] ?? '')) / 4);
            $fit = $contextLimit - $estInput - 1024;
            if ($fit < $body['max_tokens']) {
                $body['max_tokens'] = max(1024, $fit);
            }

            // Prompt caching — the single biggest cost lever. The system prompt
            // (~3k tok) and ~60 tool schemas (~20k tok) are identical on every
            // call, and the conversation prefix is identical across a tool loop
            // (re-sent within seconds). cache_control bills repeat reads at ~10%
            // of full price instead of 100%. Anthropic caches in tools → system
            // → messages order; a breakpoint caches everything up to it. Too-
            // small trailing segments are simply not cached (no error).
            if (config('vela.ai.chat.prompt_caching', true)) {
                // Mark the last *custom* tool (skip the native search server
                // tool, which has no input_schema and may reject cache_control).
                for ($i = count($body['tools'] ?? []) - 1; $i >= 0; $i--) {
                    if (isset($body['tools'][$i]['input_schema'])) {
                        $body['tools'][$i]['cache_control'] = ['type' => 'ephemeral'];
                        break;
                    }
                }
                if (!empty($body['system']) && is_string($body['system'])) {
                    $body['system'] = [[
                        'type'          => 'text',
                        'text'          => $body['system'],
                        'cache_control' => ['type' => 'ephemeral'],
                    ]];
                }
                if (!empty($body['messages'])) {
                    $li = array_key_last($body['messages']);
                    $content = $body['messages'][$li]['content'] ?? null;
                    if (is_string($content) && $content !== '') {
                        $body['messages'][$li]['content'] = [[
                            'type'          => 'text',
                            'text'          => $content,
                            'cache_control' => ['type' => 'ephemeral'],
                        ]];
                    } elseif (is_array($content) && !empty($content)) {
                        $bi = array_key_last($content);
                        $body['messages'][$li]['content'][$bi]['cache_control'] = ['type' => 'ephemeral'];
                    }
                }
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
                $this->recordAiFailure($response->status(), $response->body());
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Claude chat exception', [
                'message' => $e->getMessage(),
                'model' => $this->model,
                'exception_type' => get_class($e),
            ]);
            $this->recordAiException($e);
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

        // A half-finished earlier turn (interrupted worker, a dropped orphan
        // result, history truncation) can leave a tool_use with no result or a
        // result with no tool_use. Anthropic 400s the whole request on either,
        // so reconcile before sending — this is the single guarantee that the
        // payload is structurally valid regardless of what the DB holds.
        return $this->reconcileToolBlocks($out);
    }

    /**
     * Guarantee a valid Anthropic message sequence: every `tool_use` block is
     * paired with a `tool_result` in the very next user turn and vice-versa,
     * no message is left with empty content, consecutive same-role messages
     * are merged, and the sequence starts on a user turn.
     */
    private function reconcileToolBlocks(array $msgs): array
    {
        // 1. Drop unpaired tool blocks from BOTH sides: a `tool_use` survives
        //    only if the next user turn carries its `tool_result`, and a
        //    `tool_result` survives only if the previous assistant turn
        //    declared its `tool_use`. (Either orphan direction 400s Anthropic.)
        $blockIds = function ($msg, string $type, string $idKey): array {
            $ids = [];
            if ($msg && is_array($msg['content'] ?? null)) {
                foreach ($msg['content'] as $b) {
                    if (($b['type'] ?? '') === $type && !empty($b[$idKey])) {
                        $ids[$b[$idKey]] = true;
                    }
                }
            }
            return $ids;
        };

        $n = count($msgs);
        for ($i = 0; $i < $n; $i++) {
            $role = $msgs[$i]['role'] ?? null;
            if (!is_array($msgs[$i]['content'] ?? null)) {
                continue;
            }
            if ($role === 'assistant') {
                $resIds = ($msgs[$i + 1] ?? null) && (($msgs[$i + 1]['role'] ?? null) === 'user')
                    ? $blockIds($msgs[$i + 1], 'tool_result', 'tool_use_id')
                    : [];
                $msgs[$i]['content'] = array_values(array_filter($msgs[$i]['content'], function ($b) use ($resIds) {
                    return ($b['type'] ?? '') !== 'tool_use' || isset($resIds[$b['id'] ?? null]);
                }));
            } elseif ($role === 'user') {
                $useIds = ($i > 0 && (($msgs[$i - 1]['role'] ?? null) === 'assistant'))
                    ? $blockIds($msgs[$i - 1], 'tool_use', 'id')
                    : [];
                $msgs[$i]['content'] = array_values(array_filter($msgs[$i]['content'], function ($b) use ($useIds) {
                    return ($b['type'] ?? '') !== 'tool_result' || isset($useIds[$b['tool_use_id'] ?? null]);
                }));
            }
        }

        // 2. Drop messages whose content was fully emptied by step 1.
        $msgs = array_values(array_filter($msgs, function ($m) {
            $c = $m['content'] ?? null;
            return is_array($c) ? count($c) > 0 : ($c !== null && $c !== '');
        }));

        // 3. Merge consecutive same-role messages (a dropped turn can leave two
        //    same-role messages adjacent; a stray tool_use can never become
        //    adjacent to another because its matched result sits between them).
        $merged = [];
        foreach ($msgs as $m) {
            $last = count($merged) - 1;
            if ($last >= 0 && ($merged[$last]['role'] ?? null) === ($m['role'] ?? null)) {
                $merged[$last]['content'] = array_merge(
                    $this->contentToBlocks($merged[$last]['content']),
                    $this->contentToBlocks($m['content'])
                );
            } else {
                $merged[] = $m;
            }
        }

        // 4. Anthropic requires the first message to be a user turn.
        while (!empty($merged) && ($merged[0]['role'] ?? null) !== 'user') {
            array_shift($merged);
        }

        return $merged;
    }

    /**
     * Normalize a message `content` (string or block array) to a block array
     * so two same-role messages can be concatenated.
     */
    private function contentToBlocks($content): array
    {
        if (is_array($content)) {
            return $content;
        }
        $content = (string) $content;
        return $content === '' ? [] : [['type' => 'text', 'text' => $content]];
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
