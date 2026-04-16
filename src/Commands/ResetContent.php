<?php

namespace VelaBuild\Core\Commands;

use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetContent extends Command
{
    protected $signature = 'vela:reset-content
                            {--force : Skip confirmation prompt}';

    protected $description = 'Remove all categories and content, clean related pivot/media/translation rows.';

    public function handle(): int
    {
        if (!$this->option('force')) {
            if (!$this->confirm('This will permanently delete ALL categories, content, and related data. Continue?')) {
                $this->info('Aborted.');
                return 0;
            }
        }

        $this->info('Starting content reset...');

        DB::transaction(function () {
            $pivotDeleted = DB::table('vela_article_category')->delete();
            $this->info("  Deleted {$pivotDeleted} vela_article_category pivot rows.");

            $contentDeleted = Content::withTrashed()->forceDelete();
            $this->info("  Deleted {$contentDeleted} content rows.");

            $categoryDeleted = Category::withTrashed()->forceDelete();
            $this->info("  Deleted {$categoryDeleted} category rows.");

            $mediaDeleted = DB::table('media')
                ->whereIn('model_type', ['VelaBuild\\Core\\Models\\Content', 'VelaBuild\\Core\\Models\\Category'])
                ->delete();
            $this->info("  Deleted {$mediaDeleted} media rows.");

            $translationsDeleted = DB::table('vela_translations')
                ->whereIn('model_type', ['Content', 'Category'])
                ->delete();
            $this->info("  Deleted {$translationsDeleted} translation rows.");
        });

        $this->info('');
        $this->info('Reset complete. Now run:');
        $this->info('  php artisan db:seed');

        return 0;
    }
}
