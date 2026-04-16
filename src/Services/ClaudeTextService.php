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
