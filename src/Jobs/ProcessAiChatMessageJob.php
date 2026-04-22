<?php

namespace VelaBuild\Core\Jobs;

use VelaBuild\Core\Models\AiConversation;
use VelaBuild\Core\Models\AiMessage;
use VelaBuild\Core\Models\VelaUser;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Services\AiChat\ChatToolRegistry;
use VelaBuild\Core\Services\AiChat\ChatToolExecutor;
use VelaBuild\Core\Services\SiteContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAiChatMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 1;

    protected int $conversationId;
    protected int $userId;
    protected array $pageContext;

    public function __construct(int $conversationId, int $userId, array $pageContext = [])
    {
        $this->conversationId = $conversationId;
        $this->userId = $userId;
        $this->pageContext = $pageContext;
    }

    public function handle(): void
    {
        try {
            $conversation = AiConversation::findOrFail($this->conversationId);
            $user = VelaUser::findOrFail($this->userId);

            $aiManager = app(AiProviderManager::class);
            $textProvider = $aiManager->resolveTextProvider();
            $toolRegistry = app(ChatToolRegistry::class);
            $toolExecutor = app(ChatToolExecutor::class);
            $siteContext = app(SiteContext::class);

            Log::info('Processing AI chat message', [
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
            ]);

            // Build messages array from conversation history
            $maxMessages = config('vela.ai.chat.max_conversation_messages', 50);
            $dbMessages = $conversation->messages()
                ->orderBy('id', 'desc')
                ->take($maxMessages)
                ->get()
                ->reverse()
                ->values();

            // Build system prompt
            $systemPrompt = $this->buildSystemPrompt($siteContext, $this->pageContext, $user);

            $messages = [['role' => 'system', 'content' => $systemPrompt]];
            foreach ($dbMessages as $msg) {
                $messageEntry = ['role' => $msg->role, 'content' => $msg->content ?? ''];
                if ($msg->tool_calls) {
                    // Normalize tool_calls for OpenAI compatibility
                    $messageEntry['tool_calls'] = array_map(function ($tc) {
                        return [
                            'id' => $tc['id'] ?? ('call_' . uniqid()),
                            'type' => 'function',
                            'function' => [
                                'name' => $tc['name'] ?? $tc['function']['name'] ?? '',
                                'arguments' => is_string($tc['arguments'] ?? null)
                                    ? $tc['arguments']
                                    : json_encode($tc['arguments'] ?? $tc['function']['arguments'] ?? new \stdClass),
                            ],
                        ];
                    }, $msg->tool_calls);
                }
                if ($msg->tool_call_id) {
                    $messageEntry['tool_call_id'] = $msg->tool_call_id;
                }
                $messages[] = $messageEntry;
            }

            // Get tools available to this user
            $availableTools = $toolRegistry->forUser($user);

            // Convert tools to provider-specific format based on provider class
            $providerClass = get_class($textProvider);
            if (str_contains($providerClass, 'Claude')) {
                $formattedTools = $toolRegistry->toAnthropicFormat($availableTools);
            } elseif (str_contains($providerClass, 'Gemini')) {
                $formattedTools = $toolRegistry->toGeminiFormat($availableTools);
            } else {
                $formattedTools = $toolRegistry->toOpenAiFormat($availableTools);
            }

            // Call AI with tools
            $response = $textProvider->chat($messages, $formattedTools);

            if (!$response) {
                Log::error('AI provider returned null response', [
                    'conversation_id' => $conversation->id,
                ]);
                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => 'Sorry, I encountered an error processing your request. Please try again.',
                ]);
                return;
            }

            // Tool call loop (max 5 iterations to prevent infinite loops)
            $maxToolIterations = 5;
            $iteration = 0;

            while ($iteration < $maxToolIterations && !empty($response['tool_calls'])) {
                $iteration++;

                // Save assistant message with tool calls
                $assistantMsg = AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $response['content'] ?? null,
                    'tool_calls' => $response['tool_calls'],
                    'tokens_used' => ($response['usage']['input'] ?? 0) + ($response['usage']['output'] ?? 0),
                ]);

                // Add assistant message to context (OpenAI format)
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $response['content'] ?? '',
                    'tool_calls' => array_map(function ($tc) {
                        return [
                            'id' => $tc['id'] ?? ('call_' . uniqid()),
                            'type' => 'function',
                            'function' => [
                                'name' => $tc['name'] ?? '',
                                'arguments' => is_string($tc['arguments'] ?? null)
                                    ? $tc['arguments']
                                    : json_encode($tc['arguments'] ?? new \stdClass),
                            ],
                        ];
                    }, $response['tool_calls']),
                ];

                // Execute each tool call
                foreach ($response['tool_calls'] as $toolCall) {
                    $result = $toolExecutor->execute(
                        $toolCall['name'],
                        $toolCall['arguments'],
                        $conversation->id,
                        $assistantMsg->id,
                        $user
                    );

                    // Save tool result as a message
                    AiMessage::create([
                        'conversation_id' => $conversation->id,
                        'role' => 'tool',
                        'content' => json_encode($result),
                        'tool_call_id' => $toolCall['id'],
                    ]);

                    // Add tool result to context for next AI call
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $toolCall['id'],
                        'content' => json_encode($result),
                    ];
                }

                // Call AI again with tool results
                $response = $textProvider->chat($messages, $formattedTools);
                if (!$response) {
                    break;
                }
            }

            // Save final assistant response
            if ($response && ($response['content'] ?? null)) {
                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $response['content'],
                    'tokens_used' => ($response['usage']['input'] ?? 0) + ($response['usage']['output'] ?? 0),
                ]);
            } elseif (!$response || !($response['content'] ?? null)) {
                // No final response from AI — save a fallback so the user isn't left hanging
                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => 'I tried to process your request but ran into an issue. Could you rephrase or provide more details?',
                ]);
            }

            Log::info('AI chat message processed successfully', [
                'conversation_id' => $conversation->id,
                'tool_iterations' => $iteration,
            ]);

        } catch (\Exception $e) {
            Log::error('ProcessAiChatMessageJob failed', [
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Save error message to conversation so user gets feedback
            try {
                AiMessage::create([
                    'conversation_id' => $this->conversationId,
                    'role' => 'assistant',
                    'content' => 'Sorry, I encountered an error processing your request. Please try again.',
                ]);
            } catch (\Exception $saveError) {
                Log::error('Failed to save error message to conversation', [
                    'conversation_id' => $this->conversationId,
                    'error' => $saveError->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    private function buildSystemPrompt(SiteContext $siteContext, array $pageContext, VelaUser $user): string
    {
        $siteDesc = $siteContext->getDescription();

        $contextInfo = '';
        if (!empty($pageContext)) {
            $contextInfo = "\n\nCurrent page context:\n" . json_encode($pageContext, JSON_PRETTY_PRINT);
        }

        return "You are an AI assistant for the Vela CMS admin panel of {$siteDesc}. "
            . "You help users manage their website: create/edit content, update site configuration, customize visual styling, and generate images.\n\n"
            . "STYLING RULES - IMPORTANT:\n"
            . "- For ALL visual/CSS changes (backgrounds, colors, fonts, spacing, etc), use the update_custom_css tool. It stores CSS in the database — works on any hosting.\n"
            . "- Use scope 'site' for sitewide changes (e.g. body background, global fonts).\n"
            . "- Use scope 'page' with page_id/page_slug for page-specific styles.\n"
            . "- Always call get_custom_css first to check existing CSS before updating, so you can merge rather than overwrite.\n"
            . "- Do NOT use edit_template_file for styling — it requires filesystem write access which many hosts don't allow.\n"
            . "- The update_template_colors tool is for CSS custom properties only (--primary-color etc).\n\n"
            . "DESIGN SYSTEM - IMPORTANT:\n"
            . "- The site has a design system in /designsystem (brand docs, component patterns, palette, fonts).\n"
            . "- Browse it LAZILY via tools — do NOT assume you know the contents.\n"
            . "  • design_system_list — see what files exist before deciding what to read.\n"
            . "  • design_system_read_file — pull a specific file when it's actually relevant.\n"
            . "  • design_system_palette — prefer named palette colours over arbitrary hex values.\n"
            . "  • design_system_fonts — match font-family + source URL to what the site actually loads.\n"
            . "- When writing CSS or generating content, reference the palette + fonts wherever sensible.\n\n"
            . "GENERAL RULES:\n"
            . "- Use tools when the user asks for changes. Be helpful and concise.\n"
            . "- If unsure about a destructive change, explain and ask for confirmation first.\n"
            . "- The user's name is {$user->name}."
            . $contextInfo;
    }
}
