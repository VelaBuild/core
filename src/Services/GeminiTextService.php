<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Contracts\AiTextProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use VelaBuild\Core\Services\AiSettingsService;
class GeminiTextService implements AiTextProvider
{
    private ?string $apiKey;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    public function __construct()
    {
        $this->apiKey = app(AiSettingsService::class)->getApiKey('gemini');
    }

    public function generateText(string $prompt, int $maxTokens = 1000, float $temperature = 0.7): ?string
    {
        if (!$this->apiKey) {
            Log::warning('Vela: Gemini API key not configured');
            return null;
        }

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])->post($this->baseUrl . '?key=' . $this->apiKey, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => $maxTokens,
                        'temperature' => $temperature,
                    ],
                ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Gemini text generation successful', [
                    'prompt' => substr($prompt, 0, 100) . '...',
                    'max_tokens' => $maxTokens,
                ]);
                return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
            } else {
                Log::error('Gemini text generation failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'prompt' => substr($prompt, 0, 100) . '...',
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Gemini text generation exception', [
                'message' => $e->getMessage(),
                'prompt' => substr($prompt, 0, 100) . '...',
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
            Log::warning('Vela: Gemini API key not configured');
            return null;
        }

        try {
            // Convert messages to Gemini format
            $contents = [];
            $systemInstruction = null;

            foreach ($messages as $message) {
                if ($message['role'] === 'system') {
                    $systemInstruction = is_array($message['content'])
                        ? collect($message['content'])->where('type', 'text')->pluck('text')->implode("\n")
                        : $message['content'];
                    continue;
                }

                $role = $message['role'] === 'assistant' ? 'model' : 'user';

                // Handle array content (vision messages) vs string content
                if (is_array($message['content'] ?? null)) {
                    $parts = [];
                    foreach ($message['content'] as $block) {
                        if (($block['type'] ?? '') === 'text') {
                            $parts[] = ['text' => $block['text']];
                        } elseif (($block['type'] ?? '') === 'image') {
                            $parts[] = [
                                'inlineData' => [
                                    'mimeType' => $block['media_type'] ?? 'image/png',
                                    'data' => $block['source'],
                                ],
                            ];
                        }
                    }
                    $contents[] = ['role' => $role, 'parts' => $parts];
                } else {
                    $contents[] = [
                        'role' => $role,
                        'parts' => [['text' => $message['content'] ?? '']],
                    ];
                }
            }

            $body = [
                'contents' => $contents,
                'generationConfig' => [
                    'maxOutputTokens' => $maxTokens,
                ],
            ];

            if ($systemInstruction) {
                $body['systemInstruction'] = [
                    'parts' => [['text' => $systemInstruction]],
                ];
            }

            // Convert tools to Gemini function declarations format
            if (!empty($tools)) {
                $functionDeclarations = [];
                foreach ($tools as $tool) {
                    $functionDeclarations[] = [
                        'name' => $tool['name'],
                        'description' => $tool['description'] ?? '',
                        'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
                    ];
                }
                $body['tools'] = [
                    ['function_declarations' => $functionDeclarations],
                ];
            }

            // Native Google Search grounding. Gemini 2.x exposes it as
            // `google_search`, BUT it can't be combined with function calling
            // in the same request — Gemini rejects with INVALID_ARGUMENT
            // ("Built-in tools and Function Calling cannot be combined").
            // So only inject when no function tools were provided. With
            // function tools, the custom web_search tool (Brave/Tavily/Serper)
            // covers the gap.
            $hasFunctionTools = !empty($tools);
            if (!$hasFunctionTools && app(AiSettingsService::class)->getStatus()['native_search']) {
                $body['tools'] = $body['tools'] ?? [];
                $body['tools'][] = ['google_search' => (object) []];
            }

            $response = Http::timeout(120)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])->post($this->baseUrl . '?key=' . $this->apiKey, $body);

            if ($response->successful()) {
                $data = $response->json();

                // Normalize response. Gemini can return:
                //   • multiple text parts in one message — concatenate them
                //   • text + functionCall in the same message
                //   • zero parts with a finishReason like SAFETY / MAX_TOKENS /
                //     RECITATION — surface as content so the user sees the
                //     reason instead of "empty response"
                $contentParts = [];
                $toolCalls = null;

                $candidate = $data['candidates'][0] ?? null;
                $finishReason = $candidate['finishReason'] ?? null;

                if ($candidate) {
                    foreach ($candidate['content']['parts'] ?? [] as $part) {
                        if (isset($part['text']) && $part['text'] !== '') {
                            $contentParts[] = $part['text'];
                        } elseif (isset($part['functionCall'])) {
                            $toolCalls[] = [
                                'id' => $part['functionCall']['name'] . '_' . uniqid(),
                                'name' => $part['functionCall']['name'],
                                'arguments' => $part['functionCall']['args'] ?? [],
                            ];
                        }
                    }
                }

                $content = empty($contentParts) ? null : implode('', $contentParts);

                // Surface terminal finish reasons as content. STOP is normal,
                // anything else with empty parts means the model bailed.
                if ($content === null && empty($toolCalls) && $finishReason && $finishReason !== 'STOP') {
                    $content = match ($finishReason) {
                        'MAX_TOKENS'  => '(Gemini hit the response length limit. Increase max_tokens or ask me to continue.)',
                        'SAFETY'      => '(Gemini blocked the response on safety grounds. Rephrase the request.)',
                        'RECITATION'  => '(Gemini blocked the response as potential recitation of training data. Rephrase the request.)',
                        'PROHIBITED_CONTENT' => '(Gemini blocked the response as prohibited content. Rephrase the request.)',
                        default       => "(Gemini returned no content. finishReason={$finishReason}.)",
                    };
                }

                Log::info('Gemini chat successful', [
                    'finish_reason' => $finishReason,
                    'has_text'      => $content !== null,
                    'tool_calls'    => $toolCalls ? count($toolCalls) : 0,
                    'parts_count'   => count($candidate['content']['parts'] ?? []),
                ]);

                if ($content === null && empty($toolCalls)) {
                    Log::warning('Gemini chat returned empty', ['raw' => $data]);
                }

                $usageMetadata = $data['usageMetadata'] ?? [];

                return [
                    'content' => $content,
                    'tool_calls' => $toolCalls,
                    'usage' => [
                        'input' => $usageMetadata['promptTokenCount'] ?? 0,
                        'output' => $usageMetadata['candidatesTokenCount'] ?? 0,
                    ],
                ];
            } else {
                Log::error('Gemini chat failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Gemini chat exception', [
                'message' => $e->getMessage(),
                'exception_type' => get_class($e),
            ]);
            return null;
        }
    }
}
