<?php
namespace VelaBuild\Core\Services\AiChat;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\VelaUser;
use Illuminate\Support\Facades\Gate;

class ChatToolExecutor
{
    private ChatToolRegistry $registry;

    public function __construct(ChatToolRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Execute a tool call.
     * 1. Validate tool is in whitelist
     * 2. Check Gate permission
     * 3. Write pending action log (for write tools)
     * 4. Execute the tool handler
     * 5. Update action log to completed
     * @return array Tool result to send back to AI
     */
    public function execute(string $toolName, array $parameters, int $conversationId, int $messageId, VelaUser $user): array
    {
        // 1. Whitelist check
        if (!$this->registry->has($toolName)) {
            return ['error' => "Unknown tool: {$toolName}. Available tools: " . implode(', ', array_column($this->registry->all(), 'name'))];
        }

        $toolDef = $this->registry->get($toolName);

        // 2. Permission check
        if (!empty($toolDef['gate']) && Gate::forUser($user)->denies($toolDef['gate'])) {
            return ['error' => "Permission denied for {$toolName}. You need the '{$toolDef['gate']}' permission."];
        }

        // 3. Create pending action log for write tools
        $actionLog = null;
        if ($toolDef['write']) {
            $actionLog = AiActionLog::create([
                'conversation_id' => $conversationId,
                'message_id' => $messageId,
                'user_id' => $user->id,
                'tool_name' => $toolName,
                'parameters' => $parameters,
                'previous_state' => null,
                'status' => 'pending',
            ]);
        }

        // 4. Execute handler
        try {
            $handler = $this->resolveHandler($toolName);
            $result = $handler->execute($parameters, $actionLog);

            // 5. Update action log
            if ($actionLog) {
                $actionLog->update([
                    'result' => $result,
                    'status' => 'completed',
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            if ($actionLog) {
                $actionLog->update(['status' => 'failed', 'result' => ['error' => $e->getMessage()]]);
            }
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Undo a completed action.
     */
    public function undoAction(AiActionLog $actionLog): void
    {
        $handler = $this->resolveHandler($actionLog->tool_name);
        $handler->undo($actionLog);
        $actionLog->update(['undone_at' => now()]);
    }

    /**
     * Resolve the handler class for a tool name.
     */
    private function resolveHandler(string $toolName): Tools\BaseTool
    {
        $map = [
            'update_site_config' => Tools\UpdateSiteConfigTool::class,
            'update_template_colors' => Tools\UpdateTemplateColorsTool::class,
            'create_page' => Tools\CreatePageTool::class,
            'edit_page_content' => Tools\EditPageContentTool::class,
            'create_article' => Tools\CreateArticleTool::class,
            'edit_article_content' => Tools\EditArticleContentTool::class,
            'create_category' => Tools\CreateCategoryTool::class,
            'generate_image' => Tools\GenerateImageTool::class,
            'edit_template_file' => Tools\EditTemplateFileTool::class,
            'get_page_info' => Tools\GetPageInfoTool::class,
            'get_site_config' => Tools\GetSiteConfigTool::class,
            'list_pages' => Tools\ListPagesTool::class,
            'list_articles' => Tools\ListArticlesTool::class,
            'list_categories' => Tools\ListCategoriesTool::class,
            'get_template_file' => Tools\GetTemplateFileTool::class,
            'update_custom_css' => Tools\UpdateCustomCssTool::class,
            'get_custom_css' => Tools\GetCustomCssTool::class,
            'switch_template' => Tools\SwitchTemplateTool::class,
            'list_templates' => Tools\ListTemplatesTool::class,
            'get_template_info' => Tools\GetTemplateInfoTool::class,
        ];

        $class = $map[$toolName] ?? null;
        if (!$class) {
            throw new \RuntimeException("No handler for tool: {$toolName}");
        }

        return app($class);
    }
}
