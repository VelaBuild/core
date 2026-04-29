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
                $messageEntry = ['role' => $msg->role];

                if (!empty($msg->tool_calls) && is_array($msg->tool_calls)) {
                    // Assistant message with tool_calls. OpenAI expects content
                    // to be null (or omitted), NOT an empty string — empty
                    // string trips the "messages.[N].role tool must follow
                    // tool_calls" validator at the proxy. Normalize to null.
                    $messageEntry['content'] = $msg->content !== '' && $msg->content !== null
                        ? $msg->content
                        : null;
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
                } else {
                    $messageEntry['content'] = $msg->content ?? '';
                }

                if ($msg->tool_call_id) {
                    $messageEntry['tool_call_id'] = $msg->tool_call_id;
                }
                $messages[] = $messageEntry;
            }

            // Drop orphan tool messages — any role:'tool' that doesn't directly
            // follow an assistant message carrying its matching tool_call_id.
            // Without this, a partially-completed earlier turn (interrupted
            // queue worker, exception mid-loop) leaves orphans in the DB and
            // every subsequent request 400s on validation.
            $messages = $this->dropOrphanToolMessages($messages);

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

            // Call AI with tools. Bump output cap above the 4096 default so
            // long-form writes (comparison articles, page rebuilds) don't get
            // chopped off mid-sentence and trigger the "empty response" path.
            $maxTokens = (int) config('vela.ai.chat.max_output_tokens', 16384);
            $callChat = function () use ($textProvider, &$messages, $formattedTools, $maxTokens) {
                // Retry up to 2x on Gemini's MALFORMED_FUNCTION_CALL — those
                // are non-deterministic and usually self-resolve on retry.
                // Other null responses propagate immediately.
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $r = $textProvider->chat($messages, $formattedTools, $maxTokens);
                    if (!$r) return null;
                    if (($r['finish_reason'] ?? null) === 'MALFORMED_FUNCTION_CALL') {
                        Log::info('Retrying after MALFORMED_FUNCTION_CALL', ['attempt' => $attempt + 1]);
                        continue;
                    }
                    return $r;
                }
                return $r;
            };

            $response = $callChat();

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

            // Tool call loop. Cap is just a runaway-safety net — real tasks
            // (multi-file edits, test/revise cycles, rebuilding a page from a
            // static cache) routinely chain dozens of tool calls. Override
            // via config('vela.ai.chat.max_tool_iterations').
            $maxToolIterations = (int) config('vela.ai.chat.max_tool_iterations', 50);
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

                // Call AI again with tool results (with the same retry logic)
                $response = $callChat();
                if (!$response) {
                    break;
                }
            }

            // Save final assistant response. Multi-tool runs can fall through
            // here in 4 states; pick the most informative message for each.
            if ($response && ($response['content'] ?? null)) {
                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $response['content'],
                    'tokens_used' => ($response['usage']['input'] ?? 0) + ($response['usage']['output'] ?? 0),
                ]);
            } elseif (!$response) {
                // Provider call failed mid-loop (logged separately).
                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => 'The AI provider returned an error mid-request after ' . $iteration . ' tool call(s). Check storage/logs/laravel.log for the provider response and try again.',
                ]);
            } elseif (!empty($response['tool_calls'])) {
                // Hit max iterations while still wanting to call tools.
                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => 'I reached the tool call limit (' . $maxToolIterations . ') while working on this. The tool runs were saved — ask me to continue or summarize what was done so far.',
                ]);
            } else {
                // Response existed but had no content and no tool_calls.
                AiMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => 'The AI returned an empty response. Could you rephrase or provide more detail?',
                ]);
            }

            Log::info('AI chat message processed successfully', [
                'conversation_id' => $conversation->id,
                'tool_iterations' => $iteration,
                'final_state' => $response
                    ? (!empty($response['tool_calls']) ? 'max_iterations' : (($response['content'] ?? null) ? 'content' : 'empty'))
                    : 'provider_error',
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

    /**
     * Drop tool messages whose tool_call_id doesn't match any pending tool_call
     * id from the immediately-preceding assistant message. Iterates in order:
     * a tool message is valid iff a recent assistant turn declared a matching
     * tool_call id that hasn't been resolved yet.
     */
    private function dropOrphanToolMessages(array $messages): array
    {
        $pendingIds = [];
        $cleaned = [];
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'assistant' && !empty($msg['tool_calls'])) {
                foreach ($msg['tool_calls'] as $tc) {
                    if (!empty($tc['id'])) $pendingIds[$tc['id']] = true;
                }
                $cleaned[] = $msg;
                continue;
            }
            if (($msg['role'] ?? '') === 'tool') {
                $id = $msg['tool_call_id'] ?? null;
                if ($id && isset($pendingIds[$id])) {
                    unset($pendingIds[$id]);
                    $cleaned[] = $msg;
                } else {
                    Log::warning('Dropping orphan tool message', [
                        'tool_call_id' => $id,
                        'pending_ids'  => array_keys($pendingIds),
                    ]);
                }
                continue;
            }
            // Any other message resets pending tracking — reaching a user/system/
            // plain-assistant turn means the previous tool round is closed.
            $pendingIds = [];
            $cleaned[] = $msg;
        }
        return $cleaned;
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
            . "PRIME DIRECTIVE — DO, DON'T DESCRIBE:\n"
            . "- When the user asks you to build, fix, change, or rebuild something, USE TOOLS to do it. Do NOT respond with a numbered list of manual steps unless the user explicitly asks 'how do I…'.\n"
            . "- NEVER ask the user to provide content you can produce yourself or fetch with a tool. 'Update article 7 with a proper table' = call get_article + web_search + edit_article_content, NOT 'please provide the markdown'. The user shouldn't have to paste content back at you.\n"
            . "- 'Rebuild my homepage' / 'match the old static file' / 'make the page look like X' → call get_page_blocks → list_block_types → set_page_blocks. Don't summarize the static HTML and tell the user to recreate it themselves.\n"
            . "- 'Fix the table in article 7' / 'update article X' → call get_article first to see the current content, then edit_article_content with the rewritten markdown.\n"
            . "- If a tool errors, retry with corrected arguments. Don't give up after one failure.\n"
            . "- If you genuinely lack a tool for the request, say so plainly in one sentence — don't pad with how-to instructions.\n\n"
            . "RESEARCH BEFORE WRITING — STRICT:\n"
            . "- If the user mentions specific real-world products/companies/services/prices/stats/dates, or asks for a 'comparison', 'review', 'list', 'best of', 'top N', or ranking, you MUST call web_search BEFORE drafting. Multiple searches are encouraged.\n"
            . "- After web_search, fetch_url on at least 2-3 of the top results to read primary sources before writing.\n"
            . "- Treat search/fetch results as authoritative. Do NOT add products, brands, statistics, or quotes that aren't in those results — including 'plausible' competitors you can't verify. If you can't find enough real results, say so to the user instead of padding with fabrications.\n"
            . "- web_search uses the same OpenAI / Anthropic / Gemini key the chatbot is already running on. It does NOT need Brave / Tavily / Serper keys. NEVER tell the user to configure search keys — just call the tool. If a tool call returned 'no provider' in an EARLIER turn, that's stale; the gap was fixed and you must try again on this turn.\n"
            . "- Cite source URLs at the end of any long-form article or comparison.\n\n"
            . "VERIFY BEFORE DENYING — STRICT:\n"
            . "- NEVER tell the user that something doesn't exist, wasn't created, or wasn't saved without first verifying via list_pages / list_articles / get_page_info / get_page_blocks. Earlier in the conversation things may have succeeded under different conditions.\n"
            . "- If a previous turn looks like it failed, but you don't have explicit fresh evidence in THIS turn, call the right list/get tool and check the actual DB state before apologizing or asking the user for clarification.\n\n"
            . "CHAT REPLIES ARE SUMMARIES, NOT CONTENT — STRICT:\n"
            . "- When you write or update an article/page using a tool, the chat reply MUST be a short confirmation, not the article body. The user views the actual content inside the article/page editor — duplicating it in the chat is noise.\n"
            . "- Good: 'Updated article #7. Compared 9 dive-center products with Dive Admin as #1, sourced from 6 URLs.' Bad: pasting the full article into the chat reply.\n"
            . "- Aim for 1-3 short sentences after a write tool call. List which tools you used and any non-obvious decisions, then stop.\n\n"
            . "ARTICLES vs PAGES — TWO DIFFERENT BLOCK SYSTEMS, DON'T MIX:\n"
            . "- ARTICLES (blog posts, type=post). Use create_article / edit_article_content. The `content` parameter is plain MARKDOWN. Headings (#), lists (- or *), pipe tables (| col | col |), code blocks, images, links etc. are auto-converted to EditorJS blocks server-side. NEVER call set_page_blocks for an article — articles are not page-builder pages.\n"
            . "- PAGES (the home page, landing pages, type=page). Use set_page_blocks with rows[] of Page Builder blocks (hero / cta / posts_grid / image / text / html / gallery / accordion / contact_form / testimonials / icon_box / categories_grid / carousel / app_download / code / pricing_tiers). list_block_types lists those PAGE-BUILDER types — it does NOT include 'table' because tables live inside article content, not as page-builder blocks.\n"
            . "- Quick rule: 'article' / 'post' / 'blog' → edit_article_content + markdown. 'page' / 'home' / 'landing' / 'about' → set_page_blocks + rows.\n"
            . "- For rebuilding a page from a static cache or external URL, use read_static_cache / fetch_url then set_page_blocks. For rewriting an article, use edit_article_content with new markdown.\n"
            . "- create_page / edit_page_content take simple markdown — only for plain text pages without hero/grid/etc. layout.\n"
            . "- Page Builder workflow when needed: list_block_types → get_page_blocks (if editing) → set_page_blocks (replaces structure atomically; undoable).\n\n"
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
            . "- Be concise. If a follow-up message is short, treat it as a correction or directive on the previous turn — don't restart with a fresh summary.\n"
            . "- If unsure about a destructive change, explain in ONE sentence and ask for confirmation; don't pre-emptively list every step.\n"
            . "- The user's name is {$user->name}."
            . $contextInfo;
    }
}
