<?php

namespace VelaBuild\Core\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;

class ImportContentFromConfigJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 1;

    public function handle(): void
    {
        // Only run once per day
        $cacheKey = 'import-content-ran:' . now()->toDateString();
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->endOfDay());

        $basePath = config('vela.static.path', resource_path('static'));

        $this->importPages($basePath . '/pages');
        $this->importPosts($basePath . '/posts');
    }

    private function importPages(string $dir): void
    {
        if (!is_dir($dir)) return;

        foreach (glob($dir . '/*/config.json') as $configFile) {
            $config = json_decode(file_get_contents($configFile), true);
            if (!$config || ($config['type'] ?? '') !== 'page') continue;

            $existing = Page::where('slug', $config['slug'])->first();
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
            $config = json_decode(file_get_contents($configFile), true);
            if (!$config || ($config['type'] ?? '') !== 'post') continue;

            $existing = Content::where('slug', $config['slug'])->first();
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
                    $categoryIds = \VelaBuild\Core\Models\Category::whereIn(
                        DB::raw('LOWER(name)'),
                        array_map('strtolower', $config['categories'])
                    )->pluck('id')->toArray();

                    // Also try slug matching
                    if (empty($categoryIds)) {
                        foreach ($config['categories'] as $catSlug) {
                            $cat = \VelaBuild\Core\Models\Category::all()->first(function ($c) use ($catSlug) {
                                return \Illuminate\Support\Str::slug($c->name) === $catSlug;
                            });
                            if ($cat) $categoryIds[] = $cat->id;
                        }
                    }
                    $content->categories()->sync($categoryIds);
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
