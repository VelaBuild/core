<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Contracts\AiTextProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

use VelaBuild\Core\Services\AiSettingsService;
class OpenAiTextService implements AiTextProvider
{
    private string $apiKey;
    private string $baseUrl = 'https://api.openai.com/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = app(AiSettingsService::class)->getApiKey('openai');
    }

    /**
     * Generate text using OpenAI's GPT-4o model (raw response)
     *
     * @param string $prompt The text prompt for generation
     * @param int $maxTokens Maximum tokens to generate
     * @param float $temperature Temperature for creativity (0.0 to 2.0)
     * @return array|null Returns the raw API response or null on failure
     */
    public function generateTextRaw(string $prompt, int $maxTokens = 1000, float $temperature = 0.7): ?array
    {
        if (!$this->apiKey) {
            Log::warning('Vela: OpenAI API key not configured');
            return null;
        }

        try {
            $response = Http::timeout(120) // 2 minutes timeout
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])->post($this->baseUrl, [
                    'model' => 'gpt-4o',
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ],
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature
                ]);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('OpenAI text generation successful', [
                    'prompt' => substr($prompt, 0, 100) . '...',
                    'model' => 'gpt-4o',
                    'max_tokens' => $maxTokens,
                    'temperature' => $temperature
                ]);
                return $data;
            } else {
                Log::error('OpenAI text generation failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'prompt' => substr($prompt, 0, 100) . '...',
                    'model' => 'gpt-4o'
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('OpenAI text generation exception', [
                'message' => $e->getMessage(),
                'prompt' => substr($prompt, 0, 100) . '...',
                'model' => 'gpt-4o',
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
                'exception_type' => get_class($e)
            ]);
            return null;
        }
    }

    /**
     * Generate text using OpenAI's GPT-4o model.
     * Implements AiTextProvider::generateText()
     *
     * @return string|null The generated text content, or null on failure.
     */
    public function generateText(string $prompt, int $maxTokens = 1000, float $temperature = 0.7): ?string
    {
        $response = $this->generateTextRaw($prompt, $maxTokens, $temperature);
        if (!$response || !isset($response['choices'][0]['message']['content'])) {
            return null;
        }
        return $response['choices'][0]['message']['content'];
    }

    /**
     * Generate text with tool/function calling support (for chatbot).
     * Implements AiTextProvider::chat()
     *
     * @param array $messages Array of message objects [{role, content}]
     * @param array $tools Array of tool definitions in provider-native format
     * @return array|null Normalized response or null on failure.
     */
    public function chat(array $messages, array $tools = [], int $maxTokens = 4096): ?array
    {
        if (!$this->apiKey) {
            Log::warning('Vela: OpenAI API key not configured');
            return null;
        }

        try {
            $messages = $this->normalizeVisionMessages($messages);
            $body = [
                'model' => 'gpt-4o',
                'messages' => $messages,
                'max_tokens' => $maxTokens,
            ];

            if (!empty($tools)) {
                $body['tools'] = $tools;
            }

            $response = Http::timeout(120)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])->post($this->baseUrl, $body);

            if ($response->successful()) {
                $data = $response->json();
                Log::info('OpenAI chat successful', [
                    'message_count' => count($messages),
                    'tool_count' => count($tools),
                ]);

                $choice = $data['choices'][0] ?? null;
                $message = $choice['message'] ?? [];

                $content = $message['content'] ?? null;
                $toolCalls = null;

                if (!empty($message['tool_calls'])) {
                    $toolCalls = array_map(function ($tc) {
                        return [
                            'id' => $tc['id'],
                            'name' => $tc['function']['name'],
                            'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
                        ];
                    }, $message['tool_calls']);
                }

                return [
                    'content' => $content,
                    'tool_calls' => $toolCalls,
                    'usage' => [
                        'input' => $data['usage']['prompt_tokens'] ?? 0,
                        'output' => $data['usage']['completion_tokens'] ?? 0,
                    ],
                ];
            } else {
                Log::error('OpenAI chat failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('OpenAI chat exception', [
                'message' => $e->getMessage(),
                'exception_type' => get_class($e),
            ]);
            return null;
        }
    }

    public function supportsVision(): bool
    {
        return true;
    }

    /**
     * Normalize vision messages to OpenAI format.
     * Converts unified image blocks to OpenAI's image_url format.
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
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => 'data:' . ($block['media_type'] ?? 'image/png') . ';base64,' . $block['source'],
                        ],
                    ];
                }
                return $block;
            }, $message['content']);

            return $message;
        }, $messages);
    }

    /**
     * Generate a single content idea
     *
     * @param string $topic The topic for the idea
     * @return array|null Returns the generated idea or null on failure
     */
    public function generateSingleIdea(string $topic): ?array
    {
        $siteContext = app(\VelaBuild\Core\Services\SiteContext::class);

        $prompt = "Generate a creative content idea for {$siteContext->getDescription()}, focused on '{$topic}'.

The idea should be:
- Relevant to the site's niche and audience
- Engaging and shareable
- SEO-friendly
- Between 5-15 words for the title
- Include a brief description (1-2 sentences)

Return ONLY a valid JSON object. Do not wrap in markdown code blocks:
{
  \"title\": \"Your Idea Title Here\",
  \"description\": \"Your idea description here.\"
}";

        $response = $this->generateTextRaw($prompt, 500, 0.8);

        if (!$response || !isset($response['choices'][0]['message']['content'])) {
            return null;
        }

        $content = $response['choices'][0]['message']['content'];

        // Clean the content - remove markdown code blocks if present
        $content = preg_replace('/^```json\s*/', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);

        $idea = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error('Failed to parse AI-generated idea JSON', [
                'content' => $content,
                'json_error' => json_last_error_msg()
            ]);
            return null;
        }

        return $idea;
    }

    /**
     * Generate an SEO-optimized meta description for content.
     *
     * @param string $title The content title
     * @param string $content The content body (plaintext, first ~2000 chars)
     * @return string|null The generated description (max 160 chars) or null on failure
     */
    public function generateDescription(string $title, string $content): ?string
    {
        $prompt = "Write a concise, SEO-optimized meta description for the following article.
The description must be between 120 and 160 characters.
Do not use quotes or special formatting. Return only the description text.

Title: {$title}

Content excerpt: " . \Str::limit($content, 2000);

        $description = $this->generateText($prompt, 200, 0.5);

        if (!$description) {
            return null;
        }

        $description = trim($description);
        // Remove wrapping quotes if present
        $description = trim($description, '"\'');
        // Enforce 160 char limit
        return \Str::limit($description, 160, '');
    }
}
