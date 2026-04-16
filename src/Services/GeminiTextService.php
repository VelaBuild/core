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

            $response = Http::timeout(120)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])->post($this->baseUrl . '?key=' . $this->apiKey, $body);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('Gemini chat successful');

                // Normalize response
                $content = null;
                $toolCalls = null;

                $candidate = $data['candidates'][0] ?? null;
                if ($candidate) {
                    foreach ($candidate['content']['parts'] ?? [] as $part) {
                        if (isset($part['text'])) {
                            $content = $part['text'];
                        } elseif (isset($part['functionCall'])) {
                            $toolCalls[] = [
                                'id' => $part['functionCall']['name'] . '_' . uniqid(),
                                'name' => $part['functionCall']['name'],
                                'arguments' => $part['functionCall']['args'] ?? [],
                            ];
                        }
                    }
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
