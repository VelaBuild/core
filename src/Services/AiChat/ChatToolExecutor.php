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
        if (!empty($toolDef['write'])) {
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
        } catch (\Throwable $e) {
            // Catch \Throwable, not just \Exception: a tool that hits a
            // TypeError/Error (e.g. a bad DB expression) must come back as a
            // tool result the model can react to — never escape and crash the
            // whole turn (which leaves the UI spinning forever).
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
            'get_theme_contract' => Tools\GetThemeContractTool::class,
            'create_theme' => Tools\CreateThemeTool::class,
            'set_theme_tokens' => Tools\SetThemeTokensTool::class,
            'write_theme_file' => Tools\WriteThemeFileTool::class,
            'update_site_config' => Tools\UpdateSiteConfigTool::class,
            'update_template_colors' => Tools\UpdateTemplateColorsTool::class,
            'create_page' => Tools\CreatePageTool::class,
            'edit_page_content' => Tools\EditPageContentTool::class,
            'update_page' => Tools\UpdatePageTool::class,
            'delete_page' => Tools\DeletePageTool::class,
            'create_article' => Tools\CreateArticleTool::class,
            'edit_article_content' => Tools\EditArticleContentTool::class,
            'update_article' => Tools\UpdateArticleTool::class,
            'create_category' => Tools\CreateCategoryTool::class,
            'translate_site' => Tools\TranslateSiteTool::class,
            'generate_image' => Tools\GenerateImageTool::class,
            'edit_template_file' => Tools\EditTemplateFileTool::class,
            'get_page_info' => Tools\GetPageInfoTool::class,
            'get_site_info' => Tools\GetSiteInfoTool::class,
            'get_site_config' => Tools\GetSiteConfigTool::class,
            'list_pages' => Tools\ListPagesTool::class,
            'list_articles' => Tools\ListArticlesTool::class,
            'get_article' => Tools\GetArticleTool::class,
            'list_categories' => Tools\ListCategoriesTool::class,
            'get_template_file' => Tools\GetTemplateFileTool::class,
            'update_custom_css' => Tools\UpdateCustomCssTool::class,
            'get_custom_css' => Tools\GetCustomCssTool::class,
            'switch_template' => Tools\SwitchTemplateTool::class,
            'list_templates' => Tools\ListTemplatesTool::class,
            'get_template_info' => Tools\GetTemplateInfoTool::class,
            'design_system_list'      => Tools\DesignSystemListTool::class,
            'design_system_read_file' => Tools\DesignSystemReadFileTool::class,
            'design_system_palette'   => Tools\DesignSystemPaletteTool::class,
            'design_system_fonts'     => Tools\DesignSystemFontsTool::class,
            'read_static_cache'       => Tools\ReadStaticCacheTool::class,
            'fetch_url'               => Tools\FetchUrlTool::class,
            'web_search'              => Tools\WebSearchTool::class,
            'list_block_types'        => Tools\ListBlockTypesTool::class,
            'get_page_blocks'         => Tools\GetPageBlocksTool::class,
            'add_row'                 => Tools\AddRowTool::class,
            'update_row'              => Tools\UpdateRowTool::class,
            'delete_row'              => Tools\DeleteRowTool::class,
            'add_block'               => Tools\AddBlockTool::class,
            'update_block'            => Tools\UpdateBlockTool::class,
            'delete_block'            => Tools\DeleteBlockTool::class,
            'read_file'               => Tools\ReadFileTool::class,
            'write_file'              => Tools\WriteFileTool::class,
            'edit_file'               => Tools\EditFileTool::class,
            'search_files'            => Tools\SearchFilesTool::class,
            'run_command'             => Tools\RunCommandTool::class,
            'list_routes'             => Tools\ListRoutesTool::class,
            'database_query'          => Tools\DatabaseQueryTool::class,
            'list_directory'          => Tools\ListDirectoryTool::class,
            'get_error_log'           => Tools\GetErrorLogTool::class,
            'manage_media'            => Tools\ManageMediaTool::class,
            'manage_users'            => Tools\ManageUsersTool::class,
            'download_image'          => Tools\DownloadImageTool::class,
            'screenshot_url'          => Tools\ScreenshotUrlTool::class,
            'fetch_page_resources'    => Tools\FetchPageResourcesTool::class,
            'browse_url'              => Tools\BrowseUrlTool::class,
            'import_page_section'     => Tools\ImportPageSectionTool::class,
            'add_designed_section'    => Tools\AddDesignedSectionTool::class,
            'set_menu'                => Tools\SetMenuTool::class,
            'use_theme_for_preview'   => Tools\UseThemeForPreviewTool::class,
            'convert_section_to_block' => Tools\ConvertSectionToBlockTool::class,
            'copy_page'               => Tools\CopyPageTool::class,
            'pagespeed'               => Tools\PageSpeedTool::class,
            'diff'                    => Tools\DiffFileTool::class,
            'git'                     => Tools\GitTool::class,
            'scheduler'               => Tools\SchedulerTool::class,
            'email_preview'           => Tools\EmailPreviewTool::class,
        ];

        $class = $map[$toolName] ?? null;
        if (!$class) {
            throw new \RuntimeException("No handler for tool: {$toolName}");
        }

        return app($class);
    }
}
