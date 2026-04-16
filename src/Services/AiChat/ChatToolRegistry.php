<?php
namespace VelaBuild\Core\Services\AiChat;

class ChatToolRegistry
{
    private array $tools = [
        [
            'name' => 'update_site_config',
            'description' => 'Update a site configuration value in the database',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'The config key to update'],
                    'value' => ['type' => 'string', 'description' => 'The new value'],
                ],
                'required' => ['key', 'value'],
            ],
            'write' => true,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'update_template_colors',
            'description' => 'Update CSS color variables for the site theme',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'colors' => ['type' => 'object', 'description' => 'Key-value pairs of CSS variable name to color value'],
                ],
                'required' => ['colors'],
            ],
            'write' => true,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'update_custom_css',
            'description' => 'Add or replace custom CSS. Use scope "site" for sitewide styles (background, fonts, colors, etc) or scope "page" for a specific page. The CSS is stored in the database and injected into the page head. This is the preferred way to change visual styling.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'scope' => ['type' => 'string', 'enum' => ['site', 'page'], 'description' => 'Apply CSS sitewide or to a specific page'],
                    'css' => ['type' => 'string', 'description' => 'The CSS rules to apply (plain CSS, no <style> tags)'],
                    'page_id' => ['type' => 'integer', 'description' => 'Page ID (required when scope is "page")'],
                    'page_slug' => ['type' => 'string', 'description' => 'Page slug (alternative to page_id when scope is "page")'],
                ],
                'required' => ['scope', 'css'],
            ],
            'write' => true,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'get_custom_css',
            'description' => 'Get the current custom CSS for the site or a specific page. Always check existing CSS before updating to avoid overwriting.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'scope' => ['type' => 'string', 'enum' => ['site', 'page'], 'description' => 'Get sitewide or page-specific CSS'],
                    'page_id' => ['type' => 'integer', 'description' => 'Page ID (when scope is "page")'],
                    'page_slug' => ['type' => 'string', 'description' => 'Page slug (when scope is "page")'],
                ],
                'required' => ['scope'],
            ],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'create_page',
            'description' => 'Create a new page on the site',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'content' => ['type' => 'string', 'description' => 'Page content in markdown'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'published']],
                ],
                'required' => ['title', 'content'],
            ],
            'write' => true,
            'gate' => 'page_create',
        ],
        [
            'name' => 'edit_page_content',
            'description' => 'Edit the content of an existing page',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer'],
                    'content' => ['type' => 'string', 'description' => 'New page content in markdown'],
                ],
                'required' => ['page_id', 'content'],
            ],
            'write' => true,
            'gate' => 'page_edit',
        ],
        [
            'name' => 'create_article',
            'description' => 'Create a new blog article',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'content' => ['type' => 'string', 'description' => 'Article content in markdown'],
                    'category' => ['type' => 'string', 'description' => 'Category name'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'published']],
                ],
                'required' => ['title', 'content'],
            ],
            'write' => true,
            'gate' => 'article_create',
        ],
        [
            'name' => 'edit_article_content',
            'description' => 'Edit the content of an existing article',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'article_id' => ['type' => 'integer'],
                    'content' => ['type' => 'string', 'description' => 'New article content in markdown'],
                ],
                'required' => ['article_id', 'content'],
            ],
            'write' => true,
            'gate' => 'article_edit',
        ],
        [
            'name' => 'create_category',
            'description' => 'Create a new content category',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
                'required' => ['name'],
            ],
            'write' => true,
            'gate' => 'category_create',
        ],
        [
            'name' => 'generate_image',
            'description' => 'Generate an image using AI',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'prompt' => ['type' => 'string', 'description' => 'Detailed description of the image to generate'],
                    'type' => ['type' => 'string', 'enum' => ['logo', 'hero', 'content']],
                ],
                'required' => ['prompt'],
            ],
            'write' => true,
            'gate' => 'article_create',
        ],
        [
            'name' => 'edit_template_file',
            'description' => 'Edit a template file (CSS/HTML only, no PHP)',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'file' => ['type' => 'string', 'description' => 'File path relative to template directory'],
                    'changes' => ['type' => 'string', 'description' => 'Description of changes to make'],
                ],
                'required' => ['file', 'changes'],
            ],
            'write' => true,
            'gate' => 'ai_chat_template_edit',
        ],
        [
            'name' => 'get_page_info',
            'description' => 'Get information about a specific page',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer'],
                ],
                'required' => ['page_id'],
            ],
            'write' => false,
            'gate' => 'page_access',
        ],
        [
            'name' => 'get_site_config',
            'description' => 'Get current site configuration values',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'Optional specific config key'],
                ],
                'required' => [],
            ],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'list_pages',
            'description' => 'List all pages on the site',
            'parameters' => ['type' => 'object', 'properties' => [], 'required' => []],
            'write' => false,
            'gate' => 'page_access',
        ],
        [
            'name' => 'list_articles',
            'description' => 'List recent articles',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Number of articles to return'],
                    'category' => ['type' => 'string', 'description' => 'Filter by category name'],
                ],
                'required' => [],
            ],
            'write' => false,
            'gate' => 'article_access',
        ],
        [
            'name' => 'list_categories',
            'description' => 'List all content categories',
            'parameters' => ['type' => 'object', 'properties' => [], 'required' => []],
            'write' => false,
            'gate' => 'category_access',
        ],
        [
            'name' => 'get_template_file',
            'description' => 'Read the contents of a template file',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'file' => ['type' => 'string', 'description' => 'File path relative to template directory'],
                ],
                'required' => ['file'],
            ],
            'write' => false,
            'gate' => 'ai_chat_template_edit',
        ],
        [
            'name' => 'switch_template',
            'description' => 'Switch the active site template/theme',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'template' => ['type' => 'string', 'description' => 'The template name to activate (e.g., default, minimal, corporate, editorial, modern, dark)'],
                ],
                'required' => ['template'],
            ],
            'write' => true,
            'gate' => 'ai_chat_config_manage',
        ],
        [
            'name' => 'list_templates',
            'description' => 'List all available site templates with their descriptions and which one is currently active',
            'parameters' => [
                'type' => 'object',
                'properties' => [],
                'required' => [],
            ],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'get_template_info',
            'description' => 'Get detailed information about a specific template including its files, description, and category',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'template' => ['type' => 'string', 'description' => 'The template name to get info for'],
                ],
                'required' => ['template'],
            ],
            'write' => false,
            'gate' => null,
        ],
    ];

    /**
     * Get all tool definitions.
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Get tools filtered by user permissions.
     */
    public function forUser($user): array
    {
        return array_values(array_filter($this->tools, function ($tool) use ($user) {
            if (empty($tool['gate'])) {
                return true;
            }
            return \Gate::forUser($user)->allows($tool['gate']);
        }));
    }

    /**
     * Check if a tool name is registered.
     */
    public function has(string $name): bool
    {
        return collect($this->tools)->contains('name', $name);
    }

    /**
     * Get a single tool definition by name.
     */
    public function get(string $name): ?array
    {
        return collect($this->tools)->firstWhere('name', $name);
    }

    /**
     * Convert tool definitions to OpenAI function-calling format.
     */
    public function toOpenAiFormat(array $tools): array
    {
        return array_map(function ($tool) {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => self::fixParams($tool['parameters']),
                ],
            ];
        }, $tools);
    }

    /**
     * Convert tool definitions to Anthropic tool format.
     */
    public function toAnthropicFormat(array $tools): array
    {
        return array_map(function ($tool) {
            return [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => self::fixParams($tool['parameters']),
            ];
        }, $tools);
    }

    /**
     * Convert tool definitions to Gemini function declarations format.
     */
    public function toGeminiFormat(array $tools): array
    {
        return [[
            'function_declarations' => array_map(function ($tool) {
                return [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => self::fixParams($tool['parameters']),
                ];
            }, $tools),
        ]];
    }

    /**
     * Ensure empty arrays encode as {} not [] for API compatibility.
     */
    private static function fixParams(array $params): array
    {
        if (isset($params['properties']) && is_array($params['properties']) && empty($params['properties'])) {
            $params['properties'] = (object) [];
        }
        return $params;
    }
}
