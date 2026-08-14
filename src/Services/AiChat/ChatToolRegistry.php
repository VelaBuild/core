<?php
namespace VelaBuild\Core\Services\AiChat;

class ChatToolRegistry
{
    private array $tools = [
        [
            'name' => 'update_site_config',
            'description' => 'Update an EXISTING site configuration value in the database. Only keys the site already stores can be written — call get_site_config with no key to see them. If the user asks for a feature and no matching setting exists, this tool will refuse: say plainly that the site has no such setting and offer to build it, instead of writing a guessed key that nothing reads.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'The config key to update. Must already exist unless create_new is set.'],
                    'value' => ['type' => 'string', 'description' => 'The new value'],
                    'create_new' => ['type' => 'boolean', 'description' => 'Only set this after confirming, by reading the code, that something reads this exact key. It is not a way to bypass the check.'],
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
            'description' => 'Add or replace custom CSS. Use scope "site" for sitewide styles (background, fonts, colors, etc) or scope "page" for a specific page. The CSS is stored in the database and injected into the page head. scope "page" CSS is injected ONLY on that page, so plain selectors already only affect that page — do NOT invent a `.page-slug-…` wrapper class (it may not exist). If you want to scope to the page wrapper, use `.page-content` (present in every template) or `.page-slug-{slug}` / `.page-id-{id}` which the page wrapper now carries. This is the preferred way to change visual styling.',
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
            'description' => 'Create a new PAGE (type=page) as an empty shell — title, slug, status only. It does NOT add any content. After creating, design the layout by calling add_row + add_block (hero / cta / text / image / gallery / ...). For a quick plain-text body instead, call edit_page_content with markdown. Refuses if the title already exists (call list_pages, then update_page / add_block).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'slug' => ['type' => 'string', 'description' => 'URL slug (e.g. "terms" → /terms). Defaults to a slug derived from the title. Slugified server-side; collisions get a numeric suffix.'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'published']],
                ],
                'required' => ['title'],
            ],
            'write' => true,
            'gate' => 'page_create',
        ],
        [
            'name' => 'edit_page_content',
            'description' => 'Replace a page\'s body with a SINGLE text block rendered from markdown. Use this only for simple text pages; for real layouts use add_row / add_block instead. Existing rows/blocks are replaced.',
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
            'name' => 'update_page',
            'description' => 'Update a page\'s metadata: title, slug (rename its URL), status (draft/published/unlisted), and the search-engine fields meta_title / meta_description. Use this to RENAME a page instead of creating a duplicate, and to act on SEO requests — writing meta_title/meta_description is the concrete fix for "my page does not show up on Google", so do it rather than only advising. Pass page_id or page_slug plus the fields to change. Undoable.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'page_id'   => ['type' => 'integer'],
                    'page_slug' => ['type' => 'string', 'description' => 'Identify the page by current slug (alternative to page_id)'],
                    'title'     => ['type' => 'string'],
                    'slug'      => ['type' => 'string', 'description' => 'New slug; slugified server-side, collisions rejected.'],
                    'status'    => ['type' => 'string', 'enum' => ['draft', 'published', 'unlisted']],
                    'meta_title'       => ['type' => 'string', 'description' => 'Search-result title, max 60 characters. Empty string clears it.'],
                    'meta_description' => ['type' => 'string', 'description' => 'Search-result snippet, max 160 characters. Empty string clears it.'],
                ],
            ],
            'write' => true,
            'gate' => 'page_edit',
        ],
        [
            'name' => 'delete_page',
            'description' => 'Delete a page and all its rows/blocks. Pass page_id or page_slug (call list_pages first to confirm which). Refuses the home page. Requires confirm:true, which you may only set after the user has agreed in a later message — the first call reports what would be lost so you can put that in front of them. Undoable.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'page_id'   => ['type' => 'integer'],
                    'page_slug' => ['type' => 'string', 'description' => 'Identify the page by slug (alternative to page_id)'],
                    'confirm'   => ['type' => 'boolean', 'description' => 'Set only after the user has confirmed this specific deletion in the conversation.'],
                ],
            ],
            'write' => true,
            'gate' => 'page_delete',
        ],
        [
            'name' => 'create_article',
            'description' => 'Create a new blog article (type=post). Refuses if the title already exists — call list_articles + edit_article_content instead. Content is MARKDOWN: headings (#), lists (- or *), pipe tables (| col | col |), code, images, links auto-convert to EditorJS blocks. Do NOT use Page Builder block syntax here — articles are not page-builder pages.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'content' => ['type' => 'string', 'description' => 'Article content in markdown. Tables: use pipe syntax `| h1 | h2 |` followed by alignment row `| --- | --- |`.'],
                    'category' => ['type' => 'string', 'description' => 'Category name'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'published']],
                ],
                'required' => ['title', 'content'],
            ],
            'write' => true,
            'gate' => 'article_create',
        ],
        [
            'name' => 'translate_site',
            'description' => 'Give the site a version in another language. Pages, articles and categories keep their original wording and gain a translation alongside it — NEVER rewrite a page or article in another language yourself, that replaces the original instead of translating it. Call get_site_info first for the languages this site supports. Translates what is still missing, newest work first, up to `limit` items per call; call it again to continue. Undoable.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'locale'  => ['type' => 'string', 'description' => 'Language code to translate into, e.g. "th" or "de". Must be one of the site\'s supported locales.'],
                    'surface' => ['type' => 'string', 'enum' => ['pages', 'articles', 'categories'], 'description' => 'Limit the run to one kind of content. Omit to cover all three.'],
                    'id'      => ['type' => 'integer', 'description' => 'Translate one specific page/article/category, even if it already has a translation (it is overwritten). Requires surface.'],
                    'limit'   => ['type' => 'integer', 'description' => 'How many items to translate in this call (default 20, max 100).'],
                ],
                'required' => ['locale'],
            ],
            'write' => true,
            'gate' => 'translation_edit',
        ],
        [
            'name' => 'edit_article_content',
            'description' => 'Replace the content of an existing article (type=post). Use this for ANY article rewrite — comparison articles, reviews, guides, etc. Content is plain MARKDOWN; the server converts it to EditorJS blocks (paragraphs, headings, lists, pipe tables, code, links). Articles are NOT page-builder pages — never use the page-builder block tools for an article.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'article_id' => ['type' => 'integer'],
                    'content' => ['type' => 'string', 'description' => 'New article content in markdown. Tables: pipe syntax `| h1 | h2 |` then `| --- | --- |` then data rows.'],
                ],
                'required' => ['article_id', 'content'],
            ],
            'write' => true,
            'gate' => 'article_edit',
        ],
        [
            'name' => 'update_article',
            'description' => 'Update metadata on an existing article (type=post): title, slug, status (planned/draft/scheduled/published), description, keyword, categories. Pass only the fields you want to change. Categories is a full sync — passing it replaces the current categories with the listed names. To rewrite the article body use edit_article_content (separate tool, takes markdown). Undoable.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'article_id'  => ['type' => 'integer'],
                    'title'       => ['type' => 'string'],
                    'slug'        => ['type' => 'string', 'description' => 'Will be slugified server-side; collisions rejected.'],
                    'status'      => ['type' => 'string', 'enum' => ['planned', 'draft', 'scheduled', 'published']],
                    'description' => ['type' => 'string', 'description' => 'Short summary / SEO description.'],
                    'keyword'     => ['type' => 'string'],
                    'categories'  => [
                        'type' => 'array',
                        'description' => 'Category names. Replaces current categories. Names that don\'t exist are reported back (call create_category first to add them).',
                        'items' => ['type' => 'string'],
                    ],
                ],
                'required' => ['article_id'],
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
            'description' => 'Generate an image using AI. Returns the `url` the file was saved at — use that string exactly wherever the picture goes (an article\'s markdown, a block\'s image_url, background_image). Never write a filename you made up to describe the picture: nothing is stored there, so the page renders an empty gap. One call makes one picture; generate again for each additional one.',
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
            'name' => 'get_site_info',
            'description' => 'Get the site\'s identity: name, niche, tagline, description, URL, default + supported locales, and active public template. Call this whenever the user asks "what is this site?", "what\'s the site name?", "what locale are we in?", "what theme is this?" — far cheaper and more reliable than scanning the system prompt. No parameters.',
            'parameters' => ['type' => 'object', 'properties' => [], 'required' => []],
            'write' => false,
            'gate' => null,
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
            'name' => 'get_article',
            'description' => 'Read a single article (type=post) including its full EditorJS content JSON, title, slug, status, categories. Use this BEFORE edit_article_content when the user references an existing article ("update article 7", "fix the table", etc.) so you know what\'s currently there. Pass article_id or slug.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'article_id' => ['type' => 'integer'],
                    'slug'       => ['type' => 'string'],
                ],
            ],
            'write' => false,
            'gate' => null,
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
        // ── Design system — browse on demand so the AI doesn't receive
        //    the whole thing on every request. List first, then read files
        //    that matter for the current task.
        [
            'name' => 'design_system_list',
            'description' => 'List all files in the project design system (/designsystem folder). Returns names, sizes, and types — no content. Call this first when you need to reference brand docs, component patterns, or any design asset. Use design_system_read_file to fetch a specific file afterwards.',
            'parameters' => ['type' => 'object', 'properties' => []],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'design_system_read_file',
            'description' => 'Read one file from the project design system. Text files (md/html/txt/json/css/svg) return inline contents; binary files (images, fonts, pdf) return metadata + URL. Use design_system_list first to find available files.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Exact filename as shown by design_system_list'],
                ],
                'required' => ['name'],
            ],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'design_system_palette',
            'description' => 'Return the project colour palette (named entries with hex values). Use this when writing CSS, generating design suggestions, or picking colours — prefer the palette over arbitrary hex values.',
            'parameters' => ['type' => 'object', 'properties' => []],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'design_system_fonts',
            'description' => 'Return the project fonts (families, source URLs, weights, fallbacks). Use this when generating CSS @import lines or font-family declarations so you match what the site actually loads.',
            'parameters' => ['type' => 'object', 'properties' => []],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'list_block_types',
            'description' => 'List registered PAGE BUILDER block types (hero, cta, posts_grid, image, text, html, etc.) used by add_block / update_block for type=page records. NOT relevant to articles — articles take markdown via edit_article_content; their internal blocks (paragraph, header, list, table) are derived automatically from that markdown and aren\'t configured here.',
            'parameters' => ['type' => 'object', 'properties' => []],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'get_page_blocks',
            'description' => 'Read the full row/block structure of a page (all rows, ordered, each with its ordered blocks: type, content, settings, column layout). Returns each row id and block id — pass those to add_block / update_block / delete_block / delete_row. Read this BEFORE editing. Pass page_id or page_slug.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'page_id'   => ['type' => 'integer', 'description' => 'Page id'],
                    'page_slug' => ['type' => 'string', 'description' => 'Slug, e.g. "home" or "about"'],
                    'locale'    => ['type' => 'string', 'description' => 'Locale (defaults to site primary)'],
                ],
            ],
            'write' => false,
            'gate' => null,
        ],
        // ── Granular page-builder editing. Edit ONE row or block at a time so a
        //    small change never rewrites (and risks losing) the whole page.
        //    Always call get_page_blocks first to get the row_id / block_id you
        //    want to act on, and list_block_types for a block's content shape.
        //    A row's `name` is an internal label and does NOT render — to show a
        //    heading to visitors, add a 'text' block with the heading text.
        //    Articles (type=post) are NOT page-builder pages — use
        //    edit_article_content with markdown for those.
        [
            'name' => 'add_row',
            'description' => 'Add a new (empty) section/row to a PAGE, then fill it with add_block. Rows stack vertically. Pass page_id or page_slug. Optional row styling: name (internal label only), css_class, background_color, background_image, text_color, text_alignment, padding, width ("contained"|"full"), order (defaults to last). Returns the new row_id. Undoable.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'page_id'          => ['type' => 'integer'],
                    'page_slug'        => ['type' => 'string'],
                    'locale'           => ['type' => 'string'],
                    'name'             => ['type' => 'string', 'description' => 'Internal admin label; NOT shown on the page.'],
                    'css_class'        => ['type' => 'string'],
                    'background_color' => ['type' => 'string'],
                    'background_image' => ['type' => 'string'],
                    'text_color'       => ['type' => 'string'],
                    'text_alignment'   => ['type' => 'string'],
                    'padding'          => ['type' => 'string'],
                    'width'            => ['type' => 'string', 'enum' => ['contained', 'full']],
                    'order'            => ['type' => 'integer', 'description' => 'Position among rows (lower = higher up).'],
                ],
            ],
            'write' => true,
            'gate' => 'page_edit',
        ],
        [
            'name' => 'update_row',
            'description' => 'Update ONE row\'s settings (styling/position) without touching its blocks. Pass row_id (from get_page_blocks) plus any of: name, css_class, background_color, background_image, text_color, text_alignment, padding, width, order. Undoable.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'row_id'           => ['type' => 'integer'],
                    'name'             => ['type' => 'string', 'description' => 'Internal admin label; NOT shown on the page.'],
                    'css_class'        => ['type' => 'string'],
                    'background_color' => ['type' => 'string'],
                    'background_image' => ['type' => 'string'],
                    'text_color'       => ['type' => 'string'],
                    'text_alignment'   => ['type' => 'string'],
                    'padding'          => ['type' => 'string'],
                    'width'            => ['type' => 'string', 'enum' => ['contained', 'full']],
                    'order'            => ['type' => 'integer'],
                ],
                'required' => ['row_id'],
            ],
            'write' => true,
            'gate' => 'page_edit',
        ],
        [
            'name' => 'delete_row',
            'description' => 'Delete ONE row and all the blocks inside it. Pass row_id (from get_page_blocks). Undoable.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'row_id' => ['type' => 'integer'],
                ],
                'required' => ['row_id'],
            ],
            'write' => true,
            'gate' => 'page_edit',
        ],
        [
            'name' => 'add_block',
            'description' => 'Add ONE block to a row (call add_row first if you need a new section). Pass row_id, type (from list_block_types — hero / cta / text / image / gallery / contact_form / testimonials / icon_box / ...), and `content` + optional `settings` shaped for that type (list_block_types shows each type\'s default_content shape). Optional column_index / column_width (1-12, for multi-column rows) and order. For a "text" block, the simplest content is {"text": "your markdown — headings, paragraphs, lists"} — it is converted to the rich-text format automatically; do NOT send an empty {"text": ""}. Returns the new block_id. Undoable.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'row_id'       => ['type' => 'integer'],
                    'type'         => ['type' => 'string', 'description' => 'Block type from list_block_types.'],
                    'content'      => ['type' => 'object', 'description' => 'Content shaped for the block type (see list_block_types).'],
                    'settings'     => ['type' => 'object'],
                    'column_index' => ['type' => 'integer'],
                    'column_width' => ['type' => 'integer', 'description' => '1-12 (Bootstrap-style columns).'],
                    'order'        => ['type' => 'integer', 'description' => 'Position within the row.'],
                    'background_image' => ['type' => 'string', 'description' => 'Background image URL for this block (e.g. a hero photo). This is its own parameter — it is NOT a settings key.'],
                    'background_color' => ['type' => 'string'],
                    'text_color'       => ['type' => 'string'],
                    'text_alignment'   => ['type' => 'string'],
                    'padding'          => ['type' => 'string'],
                ],
                'required' => ['row_id', 'type'],
            ],
            'write' => true,
            'gate' => 'page_edit',
        ],
        [
            'name' => 'update_block',
            'description' => 'Update ONE existing block, or MOVE it. Pass block_id (from get_page_blocks) plus any of: content (replaces the block content), settings, column_index, column_width, order. To MOVE the block into a different row, pass row_id (the target row). Undoable.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'block_id'     => ['type' => 'integer'],
                    'content'      => ['type' => 'object'],
                    'settings'     => ['type' => 'object'],
                    'column_index' => ['type' => 'integer'],
                    'column_width' => ['type' => 'integer'],
                    'order'        => ['type' => 'integer'],
                    'row_id'       => ['type' => 'integer', 'description' => 'Move the block into this row.'],
                    'background_image' => ['type' => 'string', 'description' => 'Background image URL for this block (e.g. a hero photo). This is its own parameter — it is NOT a settings key.'],
                    'background_color' => ['type' => 'string'],
                    'text_color'       => ['type' => 'string'],
                    'text_alignment'   => ['type' => 'string'],
                    'padding'          => ['type' => 'string'],
                ],
                'required' => ['block_id'],
            ],
            'write' => true,
            'gate' => 'page_edit',
        ],
        [
            'name' => 'delete_block',
            'description' => 'Delete ONE block. Pass block_id (from get_page_blocks). Undoable.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'block_id' => ['type' => 'integer'],
                ],
                'required' => ['block_id'],
            ],
            'write' => true,
            'gate' => 'page_edit',
        ],
        [
            'name' => 'read_static_cache',
            'description' => 'Read a file from the static cache directory (resources/static/). Use this to inspect cached HTML or config.json snapshots — for example "home/index.html" to see what the homepage rendered as, or "posts/my-slug/config.json" for a post snapshot. Path is relative to the static cache root; absolute paths and `..` escapes are rejected. Returns up to 512KB of content with a truncation flag.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Path under resources/static/, e.g. "home/index.html" or "posts/my-slug/config.json"'],
                ],
                'required' => ['path'],
            ],
            'write' => false,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'web_search',
            'description' => 'Search the web for recent / factual information. Routes to whichever AI provider key is configured: Gemini (google_search grounding) or Anthropic (web_search_20250305) — no extra keys required. Use this BEFORE writing comparison articles, reviews, or any content where accuracy matters — never invent product names, statistics, or quotes. Pair with fetch_url to read the most useful results in full. Returns {summary?, results: [{title, url, description?}]}.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search query'],
                    'count' => ['type' => 'integer', 'description' => 'Number of results (1-10, default 5)'],
                ],
                'required' => ['query'],
            ],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'fetch_url',
            'description' => 'Fetch the body of an http(s) URL (e.g. an external reference site, the live production page, or a competitor for design inspiration). Returns up to 512KB of the response body with status code and content type. Refuses private/loopback/link-local hosts to prevent SSRF.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'Absolute http or https URL'],
                ],
                'required' => ['url'],
            ],
            'write' => false,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'read_file',
            'description' => 'Read the contents of a file in the project. Use this to inspect Blade templates, controllers, routes, configs, CSS, JS, and even vendor/ packages (read-only). Blocked: .env, node_modules/, .git/.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path from project root (e.g. resources/views/layouts/public.blade.php)'],
                    'offset' => ['type' => 'integer', 'description' => 'Start reading from this line number (for large files)'],
                    'limit' => ['type' => 'integer', 'description' => 'Max lines to return (for large files, default all)'],
                ],
                'required' => ['path'],
            ],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'write_file',
            'description' => 'Create or overwrite a file. Allowed directories: resources/views/, resources/lang/, resources/css/, resources/js/, public/css/, public/js/, routes/, app/, config/, database/, tests/. Blocked: .env, vendor/, node_modules/, composer.lock.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path from project root'],
                    'content' => ['type' => 'string', 'description' => 'Full file content to write'],
                ],
                'required' => ['path', 'content'],
            ],
            'write' => true,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'edit_file',
            'description' => 'Make a targeted edit to a file by replacing a search string. More precise than write_file — use this when changing part of a file. Read the file first to get the exact text to search for.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Relative path from project root'],
                    'search' => ['type' => 'string', 'description' => 'Exact text to find and replace (must be unique in the file)'],
                    'replace' => ['type' => 'string', 'description' => 'Replacement text'],
                    'replace_all' => ['type' => 'boolean', 'description' => 'Replace all occurrences (default: false, requires unique match)'],
                ],
                'required' => ['path', 'search', 'replace'],
            ],
            'write' => true,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'search_files',
            'description' => 'Search for text patterns across project files using grep or find files by name using glob. By default excludes vendor/ (set include_vendor:true to search vendor too). Always excludes node_modules/ and .git/.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'pattern' => ['type' => 'string', 'description' => 'Search pattern (regex for grep, glob for glob type)'],
                    'path' => ['type' => 'string', 'description' => 'Directory to search in (default: project root)'],
                    'type' => ['type' => 'string', 'enum' => ['grep', 'glob'], 'description' => 'Search type: grep (content search) or glob (filename search)'],
                    'file_pattern' => ['type' => 'string', 'description' => 'Filter by filename pattern (e.g. *.blade.php) — grep only'],
                    'case_insensitive' => ['type' => 'boolean', 'description' => 'Case-insensitive grep search'],
                    'include_vendor' => ['type' => 'boolean', 'description' => 'Include vendor/ directory in search (default: false)'],
                    'context_lines' => ['type' => 'integer', 'description' => 'Show N lines of context around each grep match (max: 5)'],
                    'max_results' => ['type' => 'integer', 'description' => 'Max results to return (default: 50, max: 200)'],
                ],
                'required' => ['pattern'],
            ],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'run_command',
            'description' => 'Run a shell command. Allowed: php artisan (all subcommands), composer require/remove/update/show, npm install/run, grep, find, ls, cat, head, tail, wc, diff. Safe artisan commands auto-run. Dangerous commands (migrate:fresh, db:wipe, migrate:rollback) require confirm:true. Blocked: rm -rf, sudo.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string', 'description' => 'Shell command to execute'],
                    'confirm' => ['type' => 'boolean', 'description' => 'Set to true to confirm dangerous commands (migrate:fresh, db:wipe, etc.)'],
                ],
                'required' => ['command'],
            ],
            'write' => true,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'list_routes',
            'description' => 'List registered Laravel routes. Optionally filter by URI, name, or HTTP method.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'filter' => ['type' => 'string', 'description' => 'Filter routes by URI, name, or method (optional)'],
                ],
                'required' => [],
            ],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'database_query',
            'description' => 'Run a read-only SQL SELECT query against the database. Only SELECT is allowed — no INSERT, UPDATE, DELETE, DROP, or other write operations. Returns up to 100 rows.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'SQL SELECT query to execute'],
                ],
                'required' => ['query'],
            ],
            'write' => false,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'list_directory',
            'description' => 'List files and directories at a given path. Can list recursively and filter by pattern. Use this to explore project structure.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string', 'description' => 'Directory path relative to project root (default: root)'],
                    'recursive' => ['type' => 'boolean', 'description' => 'List files recursively (max depth 4)'],
                    'pattern' => ['type' => 'string', 'description' => 'Filter by filename pattern (e.g. *.blade.php)'],
                ],
                'required' => [],
            ],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'get_error_log',
            'description' => 'Read recent entries from the Laravel error log (storage/logs/laravel.log). Useful for debugging errors after running commands or making changes.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'lines' => ['type' => 'integer', 'description' => 'Number of recent lines to read (default: 50, max: 200)'],
                    'filter' => ['type' => 'string', 'description' => 'Grep filter to narrow results (e.g. "Error", "SQL", a class name)'],
                ],
                'required' => [],
            ],
            'write' => false,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'manage_media',
            'description' => 'List, search, or get info about media files in the media library.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'info', 'search'], 'description' => 'Action to perform'],
                    'id' => ['type' => 'integer', 'description' => 'Media ID (for info action)'],
                    'query' => ['type' => 'string', 'description' => 'Search query (for search action)'],
                    'collection' => ['type' => 'string', 'description' => 'Filter by collection name (for list action)'],
                    'limit' => ['type' => 'integer', 'description' => 'Max items to return (for list, default: 20)'],
                ],
                'required' => ['action'],
            ],
            'write' => false,
            'gate' => 'media_access',
        ],
        [
            'name' => 'manage_users',
            'description' => 'List users or get user info (read-only). Cannot create, edit, or delete users.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'info'], 'description' => 'Action to perform'],
                    'id' => ['type' => 'integer', 'description' => 'User ID (for info action)'],
                ],
                'required' => ['action'],
            ],
            'write' => false,
            'gate' => 'user_access',
        ],
        [
            'name' => 'download_image',
            'description' => 'Download one image (url) or a whole batch (urls, up to 20) and save them into media storage. When copying a page, pass every image the outline reported in a single call — a rebuilt page must never point at the source site\'s server. Returns the local URL of each saved file plus any that failed.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'Image URL to download'],
                    'urls' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Several image URLs to download in one call (max 20). Takes precedence over url.'],
                    'filename' => ['type' => 'string', 'description' => 'Optional filename for a single download (auto-detected from URL if omitted)'],
                ],
                'required' => [],
            ],
            'write' => true,
            'gate' => 'article_create',
        ],
        [
            'name' => 'screenshot_url',
            'description' => 'Take a screenshot of a URL using Cloudflare Browser Rendering. Saves it to storage AND shows you the picture itself, so use it whenever you need to see how a page really looks — before copying someone\'s layout, and after changing your own. Requires CLOUDFLARE_BROWSER_RENDERING_URL to be configured.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'URL to screenshot'],
                    'width' => ['type' => 'integer', 'description' => 'Viewport width (default: 1280)'],
                    'height' => ['type' => 'integer', 'description' => 'Viewport height (default: 800)'],
                    'full_page' => ['type' => 'boolean', 'description' => 'Capture full page scroll (default: false)'],
                ],
                'required' => ['url'],
            ],
            'write' => true,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'fetch_page_resources',
            'description' => 'Fetch a web page and extract its resources: CSS (inline + linked, with actual content), JS URLs, images, meta tags, colors, fonts, and text content. Use this to understand how a remote page is built — its design, colors, fonts, layout patterns.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'URL to analyze'],
                    'resource' => ['type' => 'string', 'enum' => ['all', 'css', 'js', 'images', 'meta', 'colors', 'fonts', 'text'], 'description' => 'What to extract (default: all)'],
                ],
                'required' => ['url'],
            ],
            'write' => false,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'browse_url',
            'description' => 'Open a URL in a headless browser for full rendering. Actions: "sections" (THE ONE TO USE WHEN COPYING A PAGE — an ordered outline of every top-level section with its heading, lead text, buttons, images, repeated-card count, computed background/padding/grid and a suggested Vela block type), "design_tokens" (computed fonts, type scale, colours ranked by how much of the page uses them, :root custom properties), "extract" (flat structured data), "screenshot" (captures the page AND shows the picture to you — use it before rebuilding a layout), "html" (rendered markup, scripts/styles stripped; supports selector + raw), "evaluate" (run custom JavaScript), "pdf". "sections", "html", "extract" and "design_tokens" fall back to HTTP fetch when browser rendering is not configured.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'URL to browse'],
                    'action' => ['type' => 'string', 'enum' => ['extract', 'sections', 'design_tokens', 'screenshot', 'html', 'evaluate', 'pdf'], 'description' => 'What to do (default: extract)'],
                    'script' => ['type' => 'string', 'description' => 'JavaScript to evaluate in the page (for evaluate action)'],
                    'selector' => ['type' => 'string', 'description' => 'For action "html": return only this element — a tag ("main"), an id ("#pricing") or a class (".hero"). Use it to read a large page one section at a time.'],
                    'raw' => ['type' => 'boolean', 'description' => 'For action "html": keep scripts, styles and comments (default false — they are stripped so the budget goes on markup that shows layout)'],
                    'width' => ['type' => 'integer', 'description' => 'Viewport width for screenshot (default: 1280)'],
                    'full_page' => ['type' => 'boolean', 'description' => 'Capture full scrollable page (for screenshot)'],
                ],
                'required' => ['url'],
            ],
            'write' => false,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'copy_page',
            'description' => 'Copy a WHOLE page from another site onto this one, as it actually looks. This is THE tool for "copy this page", "rebuild this page on my site", "make me a pricing page like <url>". It reads the page\'s section outline, skips the source site\'s navigation/header/footer, and imports every content section in order with its own markup, its own (scoped) CSS and its images downloaded locally — then the wording, pictures and links are editable in the page builder as a plain form. Creates a DRAFT page when given a title, or adds to an existing page given page_id/page_slug. Do NOT rebuild a copied page out of add_row/add_block instead: that reproduces the wording and none of the design. Undoable.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'url'             => ['type' => 'string', 'description' => 'Page to copy, e.g. https://example.com/pricing'],
                    'title'           => ['type' => 'string', 'description' => 'Title for the new page (defaults to a name derived from the URL)'],
                    'page_slug'       => ['type' => 'string', 'description' => 'Slug for the new page, or the slug of an existing page to add the sections to'],
                    'page_id'         => ['type' => 'integer', 'description' => 'Existing page to add the sections to'],
                    'locale'          => ['type' => 'string'],
                    'max_sections'    => ['type' => 'integer', 'description' => 'Cap on sections copied in this call (default 15)'],
                    'include_css'     => ['type' => 'boolean', 'description' => 'Bring the source styling across, scoped to those sections (default true)'],
                    'download_images' => ['type' => 'boolean', 'description' => 'Copy the pictures into local storage (default true)'],
                ],
                'required' => ['url'],
            ],
            'write' => true,
            'gate' => 'page_create',
        ],
        [
            'name' => 'import_page_section',
            'description' => 'Copy ONE content section of a remote page onto a page here exactly as it looks: its own markup goes in as an html block, its own CSS is rewritten to reach nothing outside that block and stored on the page, and its images are downloaded to local storage. Use this when the user asked to COPY a page rather than borrow its arrangement, or for any section the block types cannot reproduce. Identify the section with section_index (the number browse_url action "sections" gave it) or selector. Call it once per section, in order. NEVER import the source site\'s navigation bar, header or footer — this site renders its own on every page from its template and menus, and the tool refuses them. The result cannot be edited in the page builder — it is raw HTML — so prefer real blocks where they genuinely fit. Undoable.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'url'             => ['type' => 'string', 'description' => 'Page to copy the section from'],
                    'page_id'         => ['type' => 'integer', 'description' => 'Page here that the section is added to'],
                    'page_slug'       => ['type' => 'string'],
                    'locale'          => ['type' => 'string'],
                    'section_index'   => ['type' => 'integer', 'description' => '1-based section number from browse_url action "sections"'],
                    'selector'        => ['type' => 'string', 'description' => 'Alternative to section_index: "#pricing", ".hero", "footer"'],
                    'include_css'     => ['type' => 'boolean', 'description' => 'Bring the source styling across, scoped to this section (default true)'],
                    'download_images' => ['type' => 'boolean', 'description' => 'Copy the pictures into local storage (default true)'],
                    'force'           => ['type' => 'boolean', 'description' => 'Import even though the section looks like a navigation bar, header or footer. Only after checking it really is page content.'],
                    'order'           => ['type' => 'integer', 'description' => 'Position among the page rows (defaults to last)'],
                ],
                'required' => ['url'],
            ],
            'write' => true,
            'gate' => 'page_edit',
        ],
        [
            'name' => 'pagespeed',
            'description' => 'Run Google PageSpeed Insights audit on a URL. Returns performance, accessibility, best practices, and SEO scores plus top improvement opportunities. Works with or without a PageSpeed API key.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string', 'description' => 'URL to audit'],
                    'strategy' => ['type' => 'string', 'enum' => ['mobile', 'desktop'], 'description' => 'Test as mobile or desktop (default: mobile)'],
                ],
                'required' => ['url'],
            ],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'diff',
            'description' => 'Show file diffs, git status, or compare strings. Actions: "file" (git diff for a file), "git" (scope: status/staged/unstaged/all/log), "strings" (diff two text blocks).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['file', 'git', 'strings'], 'description' => 'Diff type'],
                    'path' => ['type' => 'string', 'description' => 'File path (for file action)'],
                    'scope' => ['type' => 'string', 'enum' => ['status', 'staged', 'unstaged', 'all', 'log'], 'description' => 'Git scope (for git action)'],
                    'original' => ['type' => 'string', 'description' => 'Original text (for strings action)'],
                    'modified' => ['type' => 'string', 'description' => 'Modified text (for strings action)'],
                ],
                'required' => ['action'],
            ],
            'write' => false,
            'gate' => null,
        ],
        [
            'name' => 'git',
            'description' => 'Run git commands. Safe commands (status, log, diff, branch, show) run immediately. Write commands (add, commit, push, pull, checkout, merge, stash) require confirm:true. Blocked: reset --hard, push --force, clean -fd.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'subcommand' => ['type' => 'string', 'description' => 'Git subcommand and arguments (e.g. "status", "add .", "commit -m message", "push origin main")'],
                    'confirm' => ['type' => 'boolean', 'description' => 'Required for write operations'],
                ],
                'required' => ['subcommand'],
            ],
            'write' => true,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'scheduler',
            'description' => 'Manage Laravel scheduled tasks. Actions: "list" (show schedule), "run" (execute due tasks, needs confirm), "test" (run a specific artisan command, needs confirm).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'run', 'test'], 'description' => 'What to do'],
                    'command' => ['type' => 'string', 'description' => 'Artisan command name (for test action)'],
                    'confirm' => ['type' => 'boolean', 'description' => 'Required for run and test actions'],
                ],
                'required' => ['action'],
            ],
            'write' => true,
            'gate' => 'config_edit',
        ],
        [
            'name' => 'email_preview',
            'description' => 'List email templates, preview rendered HTML, or send a test email. Actions: "list" (find mailable classes and email views), "preview" (render a view to HTML), "test_send" (send a test email, needs confirm).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'preview', 'test_send'], 'description' => 'What to do'],
                    'view' => ['type' => 'string', 'description' => 'Blade view name for preview (e.g. emails.welcome)'],
                    'data' => ['type' => 'object', 'description' => 'Data to pass to the email view for preview'],
                    'to' => ['type' => 'string', 'description' => 'Email address for test_send'],
                    'subject' => ['type' => 'string', 'description' => 'Email subject for test_send'],
                    'body' => ['type' => 'string', 'description' => 'Email body for test_send'],
                    'confirm' => ['type' => 'boolean', 'description' => 'Required for test_send'],
                ],
                'required' => ['action'],
            ],
            'write' => false,
            'gate' => 'config_edit',
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
     * Convert tool definitions to a flat list. GeminiTextService wraps these
     * in `[{function_declarations: [...]}]` itself before sending — returning
     * the wrapped form here would double-wrap and crash with "Undefined array
     * key name" in the service's per-tool loop.
     */
    public function toGeminiFormat(array $tools): array
    {
        return array_map(function ($tool) {
            return [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => self::fixParams($tool['parameters']),
            ];
        }, $tools);
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
