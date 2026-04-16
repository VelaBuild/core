<?php
namespace VelaBuild\Core\Contracts;

interface AiTextProvider
{
    /**
     * Generate text from a prompt.
     * @return string|null The generated text content, or null on failure.
     */
    public function generateText(string $prompt, int $maxTokens = 1000, float $temperature = 0.7): ?string;

    /**
     * Generate text with tool/function calling support (for chatbot).
     * @param array $messages Array of message objects [{role, content}]
     * @param array $tools Array of tool definitions in provider-native format
     * @return array|null Normalized response: {content: string|null, tool_calls: [{id, name, arguments}]|null, usage: {input, output}}
     */
    public function chat(array $messages, array $tools = [], int $maxTokens = 4096): ?array;

    /**
     * Whether this provider supports vision (image) content in chat messages.
     */
    public function supportsVision(): bool;
}
