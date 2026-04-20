<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use VelaBuild\Core\Helpers\MetaTagsHelper;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;

class StaticSiteGenerator
{
    protected string $basePath;

    public function __construct()
    {
        $this->basePath = config('vela.static.path', resource_path('static'));
    }

    public function generatePage(Page $page): void
    {
        // Always write config JSON (even for drafts)
        $this->writeConfigJson($page);

        if ($page->status !== 'published') {
            return;
        }

        // Load rows.blocks relation if not already loaded
        if (!$page->relationLoaded('rows')) {
            $page->load('rows.blocks');
        }

        try {
            view()->share('canonicalUrl', url($page->slug === 'home' ? '/' : '/' . $page->slug));
            $html = view(vela_template_view('page'), compact('page'))->render();
            $this->atomicWrite($this->basePath . '/pages/' . $page->slug . '/index.html', $html);
        } catch (\Throwable $e) {
            Log::error('StaticSiteGenerator: failed to render page ' . $page->slug . ': ' . $e->getMessage());
        }
    }

    public function writeConfigJson(Page $page): void
    {
        if (!$page->relationLoaded('rows')) {
            $page->load('rows.blocks');
        }

        $rows = $page->rows->map(function ($row) {
            return [
                'name'      => $row->name,
                'css_class' => $row->css_class,
                'order'     => $row->order_column,
                'blocks'    => $row->blocks->map(function ($block) {
                    return [
                        'column_index' => $block->column_index,
                        'column_width' => $block->column_width,
                        'order'        => $block->order_column,
                        'type'         => $block->type,
                        'content'      => $block->content,
                        'settings'     => $block->settings,
                    ];
                })->values()->toArray(),
            ];
        })->values()->toArray();

        $config = [
            'type'             => 'page',
            'id'               => $page->id,
            'title'            => $page->title,
            'slug'             => $page->slug,
            'locale'           => $page->locale,
            'status'           => $page->status,
            'meta_title'       => $page->meta_title,
            'meta_description' => $page->meta_description,
            'custom_css'       => $page->custom_css,
            'custom_js'        => $page->custom_js,
            'order_column'     => $page->order_column,
            'parent_id'        => $page->parent_id,
            'rows'             => $rows,
            'last_modified'    => $page->updated_at->toISOString(),
        ];

        $this->atomicWrite(
            $this->basePath . '/pages/' . $page->slug . '/config.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function generateContent(Content $content): void
    {
        // Always write config JSON
        $this->writeContentConfigJson($content);

        if ($content->status !== 'published' || $content->type !== 'post') {
            return;
        }

        $post = $content;

        $relatedPosts = Content::where('status', 'published')
            ->where('type', 'post')
            ->where('id', '!=', $post->id)
            ->whereHas('categories', function ($query) use ($post) {
                $query->whereIn('vela_categories.id', $post->categories->pluck('id'));
            })
            ->limit(3)
            ->get();

        $categories = Category::orderBy('order_by', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        $metaTags = MetaTagsHelper::forContent($post);

        try {
            view()->share('canonicalUrl', url('/posts/' . $content->slug));
            $html = view(vela_template_view('article'), compact('post', 'relatedPosts', 'categories', 'metaTags'))->render();
            $this->atomicWrite($this->basePath . '/posts/' . $content->slug . '/index.html', $html);
        } catch (\Throwable $e) {
            Log::error('StaticSiteGenerator: failed to render content ' . $content->slug . ': ' . $e->getMessage());
        }
    }

    public function writeContentConfigJson(Content $content): void
    {
        if (!$content->relationLoaded('categories')) {
            $content->load('categories');
        }

        $categorySlugs = $content->categories->map(function ($cat) {
            return Str::slug($cat->name);
        })->values()->toArray();

        $config = [
            'type'          => 'post',
            'id'            => $content->id,
            'title'         => $content->title,
            'slug'          => $content->slug,
            'description'   => $content->description,
            'keyword'       => $content->keyword,
            'content'       => $content->content,
            'status'        => $content->status,
            'author_id'     => $content->author_id,
            'categories'    => $categorySlugs,
            'published_at'  => $content->published_at ? $content->published_at->toISOString() : null,
            'last_modified' => $content->updated_at->toISOString(),
        ];

        $this->atomicWrite(
            $this->basePath . '/posts/' . $content->slug . '/config.json',
            json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function generateHomePage(): void
    {
        // Check for a PageBuilder homepage first
        $homePage = Page::where('slug', 'home')
            ->where('status', 'published')
            ->with(['rows.blocks'])
            ->first();

        try {
            view()->share('canonicalUrl', url('/'));
            if ($homePage) {
                $page = $homePage;
                $html = view(vela_template_view('page'), compact('page'))->render();
            } else {
                $homeView = vela_template_view('home');
                $isWelcomeFallback = ($homeView === 'vela::public.home');

                if (!$isWelcomeFallback) {
                    $latestPosts = Content::where('status', 'published')
                        ->where('type', 'post')
                        ->orderByRaw('COALESCE(published_at, created_at) DESC')
                        ->limit(6)
                        ->get();

                    $categories = Category::orderBy('order_by', 'asc')
                        ->orderBy('name', 'asc')
                        ->get();

                    $featuredPosts = Content::where('status', 'published')
                        ->where('type', 'post')
                        ->orderByRaw('COALESCE(published_at, created_at) DESC')
                        ->limit(3)
                        ->get();

                    $metaTags = MetaTagsHelper::forHome();

                    $html = view($homeView, compact('latestPosts', 'categories', 'featuredPosts', 'metaTags'))->render();
                } else {
                    // Welcome fallback inside theme layout
                    $html = view('vela::public.welcome')->render();
                }
            }

            $this->atomicWrite($this->basePath . '/home/index.html', $html);
        } catch (\Throwable $e) {
            Log::error('StaticSiteGenerator: failed to render home page: ' . $e->getMessage());
        }
    }

    public function generatePostsIndex(): void
    {
        $posts = Content::where('status', 'published')
            ->where('type', 'post')
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->limit(12)
            ->get();

        $categories = Category::orderBy('order_by', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        $metaTags = MetaTagsHelper::forArticlesIndex();

        try {
            view()->share('canonicalUrl', url('/posts'));
            $html = view(vela_template_view('articles'), compact('posts', 'categories', 'metaTags'))->render();
            $this->atomicWrite($this->basePath . '/posts/index.html', $html);
        } catch (\Throwable $e) {
            Log::error('StaticSiteGenerator: failed to render posts index: ' . $e->getMessage());
        }
    }

    public function generateCategoryPage(Category $category): void
    {
        $posts = Content::where('status', 'published')
            ->where('type', 'post')
            ->whereHas('categories', function ($query) use ($category) {
                $query->where('vela_categories.id', $category->id);
            })
            ->orderByRaw('COALESCE(published_at, created_at) DESC')
            ->get();

        $categories = Category::orderBy('order_by', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        $metaTags = MetaTagsHelper::forCategory($category);

        try {
            view()->share('canonicalUrl', url('/categories/' . Str::slug($category->name)));
            $html = view(vela_template_view('categories_show'), compact('category', 'posts', 'categories', 'metaTags'))->render();
            $this->atomicWrite($this->basePath . '/categories/' . Str::slug($category->name) . '/index.html', $html);
        } catch (\Throwable $e) {
            Log::error('StaticSiteGenerator: failed to render category ' . $category->name . ': ' . $e->getMessage());
        }
    }

    public function generateCategoriesIndex(): void
    {
        $categories = Category::orderBy('order_by', 'asc')
            ->orderBy('name', 'asc')
            ->get();

        $metaTags = MetaTagsHelper::forCategoriesIndex();

        try {
            view()->share('canonicalUrl', url('/categories'));
            $html = view(vela_template_view('categories_index'), compact('categories', 'metaTags'))->render();
            $this->atomicWrite($this->basePath . '/categories/index.html', $html);
        } catch (\Throwable $e) {
            Log::error('StaticSiteGenerator: failed to render categories index: ' . $e->getMessage());
        }
    }

    public function generateTranslationSnapshot(string $type, string $slug, string $locale, string $html): void
    {
        $path = match ($type) {
            'page'             => $this->basePath . '/pages/' . $slug . '/translations/' . $locale . '.html',
            'post'             => $this->basePath . '/posts/' . $slug . '/translations/' . $locale . '.html',
            'home'             => $this->basePath . '/home/translations/' . $locale . '.html',
            'posts_index'      => $this->basePath . '/posts/translations/' . $locale . '.html',
            'category'         => $this->basePath . '/categories/' . $slug . '/translations/' . $locale . '.html',
            'categories_index' => $this->basePath . '/categories/translations/' . $locale . '.html',
            default            => null,
        };

        if ($path !== null) {
            $this->atomicWrite($path, $html);
        }
    }

    public function removeHtml(string $type, string $slug): void
    {
        $typeDir = $this->resolveTypeDir($type);
        $base = $this->basePath . '/' . $typeDir . '/' . $slug;

        $htmlFile = $base . '/index.html';
        if (is_file($htmlFile)) {
            unlink($htmlFile);
        }

        $translationsDir = $base . '/translations';
        if (is_dir($translationsDir)) {
            $this->deleteDirectory($translationsDir);
        }
    }

    public function removeAll(string $type, string $slug): void
    {
        $typeDir = $this->resolveTypeDir($type);
        $dir = $this->basePath . '/' . $typeDir . '/' . $slug;

        if (is_dir($dir)) {
            $this->deleteDirectory($dir);
        }
    }

    public function regenerateAll(): void
    {
        // Rebuild hashed CSS/JS bundles before rendering so the generated HTML
        // references the latest manifest filenames.
        try {
            app(\VelaBuild\Core\Services\AssetBundler::class)->build();
        } catch (\Throwable $e) {
            Log::error('StaticSiteGenerator: asset bundle build failed: ' . $e->getMessage());
        }

        // Generate synchronously (called from within a job)
        Page::where('status', 'published')
            ->with('rows.blocks')
            ->chunk(50, function ($pages) {
                foreach ($pages as $page) {
                    $this->generatePage($page);
                }
            });

        // Also write config for non-published pages
        Page::where('status', '!=', 'published')
            ->with('rows.blocks')
            ->chunk(50, function ($pages) {
                foreach ($pages as $page) {
                    $this->writeConfigJson($page);
                }
            });

        Content::where('status', 'published')
            ->where('type', 'post')
            ->chunk(50, function ($posts) {
                foreach ($posts as $post) {
                    $this->generateContent($post);
                }
            });

        $this->generateHomePage();
        $this->generatePostsIndex();

        Category::chunk(50, function ($categories) {
            foreach ($categories as $category) {
                $this->generateCategoryPage($category);
            }
        });

        $this->generateCategoriesIndex();
    }

    private function resolveTypeDir(string $type): string
    {
        return match ($type) {
            'page'             => 'pages',
            'post'             => 'posts',
            'category'         => 'categories',
            'pages'            => 'pages',
            'posts'            => 'posts',
            'categories'       => 'categories',
            default            => $type,
        };
    }

    private function deleteDirectory(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }

    private function atomicWrite(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, $content);
        @chmod($tmp, 0664);
        rename($tmp, $path);
    }
}
