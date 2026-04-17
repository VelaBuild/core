<?php

namespace VelaBuild\Core\Http\Controllers\Api;

use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\SiteConfigWriter;
use VelaBuild\Core\Services\SiteContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class McpController extends Controller
{
    /**
     * Settings groups: which keys are readable/writable and their validation rules.
     */
    private const SETTINGS = [
        'general' => [
            'keys' => ['site_name', 'site_niche', 'site_tagline', 'site_description'],
            'rules' => [
                'site_name' => 'nullable|string|max:255',
                'site_niche' => 'nullable|string|max:255',
                'site_tagline' => 'nullable|string|max:500',
                'site_description' => 'nullable|string|max:1000',
            ],
        ],
        'pwa' => [
            'keys' => ['pwa_enabled', 'pwa_name', 'pwa_short_name', 'pwa_description', 'pwa_display', 'pwa_theme_color', 'pwa_background_color'],
            'rules' => [
                'pwa_enabled' => 'nullable|boolean',
                'pwa_name' => 'nullable|string|max:255',
                'pwa_short_name' => 'nullable|string|max:12',
                'pwa_description' => 'nullable|string|max:500',
                'pwa_display' => 'nullable|in:standalone,fullscreen,minimal-ui,browser',
                'pwa_theme_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'pwa_background_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            ],
        ],
        'visibility' => [
            'keys' => ['visibility_mode', 'visibility_noindex', 'visibility_block_ai', 'visibility_holding_page'],
            'rules' => [
                'visibility_mode' => 'nullable|in:public,restricted',
                'visibility_noindex' => 'nullable|boolean',
                'visibility_block_ai' => 'nullable|boolean',
                'visibility_holding_page' => 'nullable|boolean',
            ],
        ],
        'x402' => [
            'keys' => ['x402_enabled', 'x402_mode', 'x402_pay_to', 'x402_price_usd', 'x402_network', 'x402_description'],
            'rules' => [
                'x402_enabled' => 'nullable|boolean',
                'x402_mode' => 'nullable|in:sitewide,per_page',
                'x402_pay_to' => 'nullable|string|max:255',
                'x402_price_usd' => 'nullable|numeric|min:0.001|max:1000',
                'x402_network' => 'nullable|in:base,ethereum,polygon,arbitrum,optimism',
                'x402_description' => 'nullable|string|max:500',
            ],
        ],
        'gdpr' => [
            'keys' => ['gdpr_enabled', 'gdpr_privacy_url'],
            'rules' => [
                'gdpr_enabled' => 'nullable|boolean',
                'gdpr_privacy_url' => 'nullable|string|max:255',
            ],
        ],
        'app' => [
            'keys' => ['app_ios_url', 'app_android_url', 'app_name', 'app_custom_scheme'],
            'rules' => [
                'app_ios_url' => 'nullable|url|max:500',
                'app_android_url' => 'nullable|url|max:500',
                'app_name' => 'nullable|string|max:255',
                'app_custom_scheme' => 'nullable|string|max:50',
            ],
        ],
    ];

    private const CACHE_TYPES = ['all', 'home', 'pages', 'articles', 'images', 'pwa'];

    /**
     * Site information and available resources.
     */
    public function index()
    {
        $siteContext = app(SiteContext::class);

        return response()->json([
            'name' => $siteContext->getName(),
            'description' => $siteContext->getDescription(),
            'resources' => [
                'posts' => url('/api/mcp/posts'),
                'pages' => url('/api/mcp/pages'),
                'categories' => url('/api/mcp/categories'),
                'settings' => url('/api/mcp/settings'),
                'cache' => url('/api/mcp/cache/{type}'),
            ],
        ]);
    }

    /**
     * List published posts.
     */
    public function posts(Request $request)
    {
        $query = Content::where('status', 'published')
            ->orderByRaw('COALESCE(published_at, created_at) DESC');

        if ($request->filled('category')) {
            $query->whereHas('categories', function ($q) use ($request) {
                $q->where('vela_categories.id', $request->category)
                    ->orWhere('vela_categories.name', $request->category);
            });
        }

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $posts = $query->paginate($perPage);

        return response()->json([
            'data' => $posts->map(function (Content $post) {
                return $this->formatPost($post);
            }),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    /**
     * Get a single post by slug.
     */
    public function post(string $slug)
    {
        $post = Content::where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return response()->json(['data' => $this->formatPost($post, true)]);
    }

    /**
     * List published pages.
     */
    public function pages(Request $request)
    {
        $query = Page::where('status', 'published')
            ->orderBy('order_column');

        $perPage = min((int) $request->get('per_page', 20), 100);
        $pages = $query->paginate($perPage);

        return response()->json([
            'data' => $pages->map(function (Page $page) {
                return $this->formatPage($page);
            }),
            'meta' => [
                'current_page' => $pages->currentPage(),
                'last_page' => $pages->lastPage(),
                'per_page' => $pages->perPage(),
                'total' => $pages->total(),
            ],
        ]);
    }

    /**
     * Get a single page by slug.
     */
    public function page(string $slug)
    {
        $page = Page::where('slug', $slug)
            ->where('status', 'published')
            ->with(['rows.blocks'])
            ->firstOrFail();

        return response()->json(['data' => $this->formatPage($page, true)]);
    }

    /**
     * List categories.
     */
    public function categories()
    {
        $categories = Category::orderBy('order_by')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $categories->map(function (Category $cat) {
                return [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'icon' => $cat->icon,
                    'image' => $cat->image ? $cat->image->getUrl() : null,
                    'post_count' => $cat->contents()->where('status', 'published')->count(),
                ];
            }),
        ]);
    }

    /**
     * List all settings, grouped.
     */
    public function settings()
    {
        $result = [];

        foreach (self::SETTINGS as $group => $def) {
            $values = VelaConfig::whereIn('key', $def['keys'])
                ->pluck('value', 'key')
                ->toArray();

            $groupData = [];
            foreach ($def['keys'] as $key) {
                $groupData[$key] = $values[$key] ?? null;
            }
            $result[$group] = $groupData;
        }

        return response()->json(['data' => $result]);
    }

    /**
     * Get settings for a single group.
     */
    public function settingsGroup(string $group)
    {
        if (!isset(self::SETTINGS[$group])) {
            return response()->json(['error' => 'Unknown settings group', 'available' => array_keys(self::SETTINGS)], 404);
        }

        $def = self::SETTINGS[$group];
        $values = VelaConfig::whereIn('key', $def['keys'])
            ->pluck('value', 'key')
            ->toArray();

        $data = [];
        foreach ($def['keys'] as $key) {
            $data[$key] = $values[$key] ?? null;
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Update settings for a group.
     */
    public function updateSettings(Request $request, string $group)
    {
        if (!isset(self::SETTINGS[$group])) {
            return response()->json(['error' => 'Unknown settings group', 'available' => array_keys(self::SETTINGS)], 404);
        }

        $def = self::SETTINGS[$group];

        // Validate input against group-specific rules
        $validator = Validator::make($request->all(), $def['rules']);
        if ($validator->fails()) {
            return response()->json(['error' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $updated = [];
        foreach ($def['keys'] as $key) {
            if ($request->has($key)) {
                $value = $request->input($key);

                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }

                VelaConfig::updateOrCreate(['key' => $key], ['value' => (string) $value]);
                $updated[$key] = (string) $value;
            }
        }

        if (empty($updated)) {
            return response()->json(['error' => 'No valid keys provided', 'accepted' => $def['keys']], 422);
        }

        app(SiteConfigWriter::class)->write();

        Log::info('MCP API: settings updated', [
            'group' => $group,
            'keys' => array_keys($updated),
            'ip' => $request->ip(),
        ]);

        return response()->json(['data' => $updated, 'message' => 'Settings updated']);
    }

    /**
     * Clear a cache by type.
     */
    public function clearCache(Request $request, string $type)
    {
        if (!in_array($type, self::CACHE_TYPES)) {
            return response()->json(['error' => 'Unknown cache type', 'available' => self::CACHE_TYPES], 404);
        }

        $staticPath = config('vela.static.path', resource_path('static'));

        switch ($type) {
            case 'all':
                foreach (['home', 'posts', 'categories', 'pages'] as $dir) {
                    $this->deleteDirectory($staticPath . '/' . $dir);
                }
                $viewPath = config('view.compiled', storage_path('framework/views'));
                if (is_dir($viewPath)) {
                    foreach (glob($viewPath . '/*.php') as $file) {
                        @unlink($file);
                    }
                }
                \Illuminate\Support\Facades\Artisan::call('config:clear');
                \Illuminate\Support\Facades\Artisan::call('route:clear');
                $this->deletePwaCache();
                $this->deleteImageCache();
                app(\VelaBuild\Core\Services\StaticSiteGenerator::class)->regenerateAll();
                break;

            case 'home':
                $this->deleteDirectory($staticPath . '/home');
                app(\VelaBuild\Core\Services\StaticSiteGenerator::class)->regenerateAll();
                break;

            case 'pages':
                $this->deleteDirectory($staticPath . '/pages');
                app(\VelaBuild\Core\Services\StaticSiteGenerator::class)->regenerateAll();
                break;

            case 'articles':
                $this->deleteDirectory($staticPath . '/posts');
                $this->deleteDirectory($staticPath . '/categories');
                app(\VelaBuild\Core\Services\StaticSiteGenerator::class)->regenerateAll();
                break;

            case 'images':
                $this->deleteImageCache();
                break;

            case 'pwa':
                $this->deletePwaCache();
                break;
        }

        Log::info('MCP API: cache cleared', [
            'type' => $type,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => "Cache '{$type}' cleared"]);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($dir);
    }

    private function deleteImageCache(): void
    {
        $path = config('vela.images.cache_path', storage_path('app/image-cache'));
        $this->deleteDirectory($path);
    }

    private function deletePwaCache(): void
    {
        $dir = storage_path('app/pwa');
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.json') as $file) {
                @unlink($file);
            }
        }
    }

    private function formatPost(Content $post, bool $full = false): array
    {
        $data = [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'url' => url('/posts/' . $post->slug),
            'description' => $post->description,
            'image' => $post->main_image ? $post->main_image->getUrl() : null,
            'categories' => $post->categories->pluck('name')->toArray(),
            'published_at' => ($post->published_at ?? $post->created_at)?->toIso8601String(),
        ];

        if ($full) {
            $data['content'] = $post->content;
            $data['keyword'] = $post->keyword;
            $data['author'] = $post->author ? $post->author->name : null;
        }

        return $data;
    }

    private function formatPage(Page $page, bool $full = false): array
    {
        $data = [
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'url' => url($page->slug === 'home' ? '/' : '/' . $page->slug),
            'meta_title' => $page->meta_title,
            'meta_description' => $page->meta_description,
            'image' => $page->og_image ? $page->og_image->getUrl() : null,
            'parent_id' => $page->parent_id,
        ];

        if ($full) {
            $data['rows'] = $page->rows->map(function ($row) {
                return [
                    'id' => $row->id,
                    'name' => $row->name,
                    'order' => $row->order_column,
                    'blocks' => $row->blocks->map(function ($block) {
                        return [
                            'id' => $block->id,
                            'type' => $block->type,
                            'content' => $block->content,
                            'settings' => $block->settings,
                            'column_width' => $block->column_width,
                            'order' => $block->order_column,
                        ];
                    }),
                ];
            });
        }

        return $data;
    }
}
