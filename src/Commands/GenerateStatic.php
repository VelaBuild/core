<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Services\StaticSiteGenerator;

class GenerateStatic extends Command
{
    protected $signature = 'vela:generate-static {--type=all : pages|posts|categories|home|all} {--clear : Remove all static files before generating}';
    protected $description = 'Generate static HTML and config files for all published content';

    public function handle(): int
    {
        $type = $this->option('type');

        if ($this->option('clear')) {
            $staticPath = config('vela.static.path', resource_path('static'));
            $this->info("Clearing static files in {$staticPath}...");
            $dirs = ['home', 'posts', 'categories', 'pages'];
            foreach ($dirs as $dir) {
                $path = $staticPath . '/' . $dir;
                if (is_dir($path)) {
                    $this->deleteDirectory($path);
                    $this->info("  Removed {$dir}/");
                }
            }
        }
        $generator = app(StaticSiteGenerator::class);

        // Warn if APP_URL looks like staging/dev
        $appUrl = config('app.url', '');
        if (preg_match('/(staging|dev|local|localhost)/i', $appUrl)) {
            $this->warn("APP_URL contains '{$appUrl}' — generated static files will use this URL. Set APP_URL to production domain before generating for deployment.");
        }

        $counts = ['pages' => 0, 'posts' => 0, 'categories' => 0, 'translations' => 0];

        if (in_array($type, ['all', 'pages'])) {
            $pages = \VelaBuild\Core\Models\Page::where('status', 'published')->get();
            foreach ($pages as $page) {
                $generator->generatePage($page);
                $counts['pages']++;
            }
            // Also write config for draft pages
            $drafts = \VelaBuild\Core\Models\Page::where('status', '!=', 'published')->get();
            foreach ($drafts as $page) {
                $generator->writeConfigJson($page);
            }
            $this->info("Pages: {$counts['pages']} generated");
        }

        if (in_array($type, ['all', 'posts'])) {
            $posts = \VelaBuild\Core\Models\Content::where('status', 'published')->get();
            foreach ($posts as $post) {
                $generator->generateContent($post);
                $counts['posts']++;
            }
            $this->info("Posts: {$counts['posts']} generated");
        }

        if (in_array($type, ['all', 'categories'])) {
            $categories = \VelaBuild\Core\Models\Category::all();
            foreach ($categories as $category) {
                $generator->generateCategoryPage($category);
                $counts['categories']++;
            }
            $generator->generateCategoriesIndex();
            $this->info("Categories: {$counts['categories']} generated");
        }

        if (in_array($type, ['all', 'home'])) {
            $generator->generateHomePage();
            $this->info('Home page generated');
        }

        if (in_array($type, ['all', 'posts'])) {
            $generator->generatePostsIndex();
            $this->info('Posts index generated');
        }

        $this->info('Static generation complete.');

        return Command::SUCCESS;
    }

    private function deleteDirectory(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getRealPath()) : @unlink($file->getRealPath());
        }
        @rmdir($dir);
    }
}
