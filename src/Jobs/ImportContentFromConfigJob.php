<?php

namespace VelaBuild\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;

class ImportContentFromConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 1;

    public array $report = [
        'categories' => ['scanned' => 0, 'created' => 0, 'restored' => 0, 'updated' => 0, 'skipped' => []],
        'pages'      => ['scanned' => 0, 'created' => 0, 'restored' => 0, 'updated' => 0],
        'posts'      => ['scanned' => 0, 'created' => 0, 'restored' => 0, 'updated' => 0],
    ];

    public function handle(): void
    {
        // Only run once per day
        $cacheKey = 'import-content-ran:' . now()->toDateString();
        if (Cache::has($cacheKey)) {
            \Illuminate\Support\Facades\Log::info('ImportContentFromConfigJob: skipped (daily lock active)');
            return;
        }
        Cache::put($cacheKey, true, now()->endOfDay());

        $basePath = config('vela.static.path', resource_path('static'));
        \Illuminate\Support\Facades\Log::info('ImportContentFromConfigJob: starting', ['base_path' => $basePath]);

        // Categories imported first so post category relationships resolve.
        $this->importCategories($basePath . '/categories');
        $this->importPages($basePath . '/pages');
        $this->importPosts($basePath . '/posts');

        \Illuminate\Support\Facades\Log::info('ImportContentFromConfigJob: complete', $this->report);
    }

    private function importCategories(string $dir): void
    {
        if (!is_dir($dir)) {
            \Illuminate\Support\Facades\Log::info('ImportContentFromConfigJob: categories dir missing', ['dir' => $dir]);
            return;
        }

        // Two-pass: first any folders that DO have a config.json (preferred —
        // keeps icon/order/image), then folders without a config.json where we
        // synthesize a Category from the folder name. The second pass is what
        // recovers categories from a static cache that pre-dates the
        // writeCategoryConfigJson change.
        foreach (glob($dir . '/*/config.json') as $configFile) {
            $this->report['categories']['scanned']++;
            $config = $this->readConfig($configFile);
            if (!$config || ($config['type'] ?? '') !== 'category') {
                $this->report['categories']['skipped'][] = $configFile . ' (invalid type)';
                continue;
            }
            $this->upsertCategory($config['name'] ?? null, $config);
        }

        foreach (glob($dir . '/*', GLOB_ONLYDIR) as $folder) {
            $slug = basename($folder);
            if ($slug === '' || $slug === 'translations') {
                $this->report['categories']['skipped'][] = $folder . ' (translations dir)';
                continue;
            }
            if (is_file($folder . '/config.json')) {
                continue; // already handled above
            }
            if (!is_file($folder . '/index.html')) {
                $this->report['categories']['skipped'][] = $folder . ' (no index.html)';
                continue;
            }

            $this->report['categories']['scanned']++;
            $name = Str::title(str_replace('-', ' ', $slug));
            $this->upsertCategory($name, []);
        }
    }

    private function upsertCategory(?string $name, array $config): void
    {
        if (!$name) return;

        $existing = Category::withTrashed()
            ->where(DB::raw('LOWER(name)'), strtolower($name))
            ->first();

        if ($existing && $existing->trashed()) {
            $existing->restore();
            $this->report['categories']['restored']++;
        }

        if (!$existing) {
            $category = Category::create([
                'name'     => $name,
                'icon'     => $config['icon'] ?? null,
                'order_by' => $config['order_by'] ?? 0,
            ]);
            $this->attachMediaFromUrl($category, 'image', $config['image_url'] ?? null);
            $this->report['categories']['created']++;
            return;
        }

        $existing->update([
            'icon'     => $config['icon'] ?? $existing->icon,
            'order_by' => $config['order_by'] ?? $existing->order_by,
        ]);
        if ($existing->getMedia('image')->count() === 0) {
            $this->attachMediaFromUrl($existing, 'image', $config['image_url'] ?? null);
        }
        $this->report['categories']['updated']++;
    }

    // Best-effort media re-attach. Spatie's addMediaFromUrl downloads the
    // remote file (or local URL) and copies it into the configured disk.
    // Failures are non-fatal — a missing image shouldn't block content import.
    private function attachMediaFromUrl($model, string $collection, ?string $url): void
    {
        if (!$url || !method_exists($model, 'addMediaFromUrl')) {
            return;
        }
        try {
            $model->addMediaFromUrl($url)->toMediaCollection($collection);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ImportContentFromConfigJob: media attach failed', [
                'model'      => get_class($model) . '#' . ($model->id ?? '?'),
                'collection' => $collection,
                'url'        => $url,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    // Strip dev-host prefixes (localhost, 127.0.0.1, *.test, *.local) from any
    // absolute URLs baked into a config.json so imported content references
    // images/links by relative path on whatever host runs the import.
    private function normalizeUrls(string $jsonStr): string
    {
        $patterns = [
            '#https?://localhost(:\d+)?#i',
            '#https?://127\.0\.0\.1(:\d+)?#i',
            '#https?://[a-z0-9\-]+\.(test|local|localhost)(:\d+)?#i',
        ];
        return preg_replace($patterns, '', $jsonStr);
    }

    private function readConfig(string $configFile): ?array
    {
        $raw = file_get_contents($configFile);
        if ($raw === false) return null;
        $config = json_decode($this->normalizeUrls($raw), true);
        return is_array($config) ? $config : null;
    }

    private function importPages(string $dir): void
    {
        if (!is_dir($dir)) return;

        foreach (glob($dir . '/*/config.json') as $configFile) {
            $config = $this->readConfig($configFile);
            if (!$config || ($config['type'] ?? '') !== 'page') continue;

            // withTrashed: a soft-deleted row still holds the unique
            // (locale, slug) index, so a fresh Page::create would collide.
            // Restore + overwrite instead.
            $existing = Page::withTrashed()->where('slug', $config['slug'])->first();
            if ($existing && $existing->trashed()) {
                $existing->restore();
            }
            $configModified = $config['last_modified'] ?? null;

            if (!$existing) {
                // Import new page
                DB::transaction(function () use ($config) {
                    $page = Page::create([
                        'title'            => $config['title'],
                        'slug'             => $config['slug'],
                        'locale'           => $config['locale'] ?? config('vela.primary_language', 'en'),
                        'status'           => $config['status'] ?? 'draft',
                        'meta_title'       => $config['meta_title'] ?? null,
                        'meta_description' => $config['meta_description'] ?? null,
                        'custom_css'       => $config['custom_css'] ?? null,
                        'custom_js'        => $config['custom_js'] ?? null,
                        'order_column'     => $config['order_column'] ?? 0,
                        'parent_id'        => $config['parent_id'] ?? null,
                    ]);

                    // Recreate rows and blocks
                    foreach ($config['rows'] ?? [] as $rowData) {
                        $row = PageRow::create([
                            'page_id'      => $page->id,
                            'name'         => $rowData['name'] ?? null,
                            'css_class'    => $rowData['css_class'] ?? null,
                            'order_column' => $rowData['order'] ?? 0,
                        ]);
                        foreach ($rowData['blocks'] ?? [] as $blockData) {
                            PageBlock::create([
                                'page_row_id'  => $row->id,
                                'column_index' => $blockData['column_index'] ?? 0,
                                'column_width' => $blockData['column_width'] ?? 12,
                                'order_column' => $blockData['order'] ?? 0,
                                'type'         => $blockData['type'],
                                'content'      => $blockData['content'] ?? null,
                                'settings'     => $blockData['settings'] ?? null,
                            ]);
                        }
                    }
                });
            } elseif ($configModified) {
                // Update only if config is newer
                $dbModified = $existing->updated_at->toISOString();
                if ($configModified > $dbModified) {
                    $existing->update([
                        'title'            => $config['title'],
                        'status'           => $config['status'] ?? $existing->status,
                        'meta_title'       => $config['meta_title'] ?? $existing->meta_title,
                        'meta_description' => $config['meta_description'] ?? $existing->meta_description,
                        'custom_css'       => $config['custom_css'] ?? $existing->custom_css,
                        'custom_js'        => $config['custom_js'] ?? $existing->custom_js,
                        'order_column'     => $config['order_column'] ?? $existing->order_column,
                    ]);

                    // Rebuild rows/blocks
                    DB::transaction(function () use ($existing, $config) {
                        $existing->rows()->each(function ($row) {
                            $row->blocks()->delete();
                            $row->delete();
                        });

                        foreach ($config['rows'] ?? [] as $rowData) {
                            $row = PageRow::create([
                                'page_id'      => $existing->id,
                                'name'         => $rowData['name'] ?? null,
                                'css_class'    => $rowData['css_class'] ?? null,
                                'order_column' => $rowData['order'] ?? 0,
                            ]);
                            foreach ($rowData['blocks'] ?? [] as $blockData) {
                                PageBlock::create([
                                    'page_row_id'  => $row->id,
                                    'column_index' => $blockData['column_index'] ?? 0,
                                    'column_width' => $blockData['column_width'] ?? 12,
                                    'order_column' => $blockData['order'] ?? 0,
                                    'type'         => $blockData['type'],
                                    'content'      => $blockData['content'] ?? null,
                                    'settings'     => $blockData['settings'] ?? null,
                                ]);
                            }
                        }
                    });
                }
            }
        }
    }

    private function importPosts(string $dir): void
    {
        if (!is_dir($dir)) return;

        foreach (glob($dir . '/*/config.json') as $configFile) {
            $config = $this->readConfig($configFile);
            if (!$config || ($config['type'] ?? '') !== 'post') continue;

            $existing = Content::withTrashed()->where('slug', $config['slug'])->first();
            if ($existing && $existing->trashed()) {
                $existing->restore();
            }
            $configModified = $config['last_modified'] ?? null;

            if (!$existing) {
                $content = Content::create([
                    'title'        => $config['title'],
                    'slug'         => $config['slug'],
                    'type'         => 'post',
                    'description'  => $config['description'] ?? null,
                    'keyword'      => $config['keyword'] ?? null,
                    'content'      => is_array($config['content'] ?? null)
                        ? json_encode($config['content'])
                        : ($config['content'] ?? null),
                    'status'       => $config['status'] ?? 'draft',
                    'author_id'    => $config['author_id'] ?? null,
                    'published_at' => $config['published_at'] ?? null,
                ]);

                // Sync categories by slug matching
                if (!empty($config['categories'])) {
                    $categoryIds = Category::whereIn(
                        DB::raw('LOWER(name)'),
                        array_map('strtolower', $config['categories'])
                    )->pluck('id')->toArray();

                    // Also try slug matching
                    if (empty($categoryIds)) {
                        foreach ($config['categories'] as $catSlug) {
                            $cat = Category::all()->first(function ($c) use ($catSlug) {
                                return Str::slug($c->name) === $catSlug;
                            });
                            if ($cat) $categoryIds[] = $cat->id;
                        }
                    }
                    $content->categories()->sync($categoryIds);
                }

                // Re-attach images that the previous DB used to own.
                $this->attachMediaFromUrl($content, 'main_image', $config['main_image_url'] ?? null);
                foreach ((array) ($config['gallery_urls'] ?? []) as $url) {
                    $this->attachMediaFromUrl($content, 'gallery', $url);
                }
                foreach ((array) ($config['content_image_urls'] ?? []) as $url) {
                    $this->attachMediaFromUrl($content, 'content_images', $url);
                }
            } elseif ($configModified) {
                $dbModified = $existing->updated_at->toISOString();
                if ($configModified > $dbModified) {
                    $existing->update([
                        'title'       => $config['title'],
                        'description' => $config['description'] ?? $existing->description,
                        'keyword'     => $config['keyword'] ?? $existing->keyword,
                        'content'     => is_array($config['content'] ?? null)
                            ? json_encode($config['content'])
                            : ($config['content'] ?? $existing->content),
                        'status'      => $config['status'] ?? $existing->status,
                    ]);
                }
            }
        }
    }
}
