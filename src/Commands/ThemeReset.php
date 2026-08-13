<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\SiteConfigWriter;
use VelaBuild\Core\Services\StaticSiteGenerator;
use VelaBuild\Core\Vela;

class ThemeReset extends Command
{
    protected $signature = 'vela:theme-reset
                            {--template=default : Slug of the template to switch back to}
                            {--keep-options : Keep theme_* and css_* customizations instead of wiping them}
                            {--regenerate : Rebuild the static HTML after resetting}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Reset the active theme (default: "default"), wipe theme customizations, and clear every cache that pins the old theme.';

    public function handle(Vela $vela, SiteConfigWriter $writer): int
    {
        $target = $this->option('template');
        $templates = $vela->templates()->all();

        if (! array_key_exists($target, $templates)) {
            $this->error("Unknown template '{$target}'. Available: " . implode(', ', array_keys($templates)));

            return 1;
        }

        $current = VelaConfig::where('key', 'active_template')->value('value')
            ?: config('vela.template.active', 'default');

        $wipeOptions = ! $this->option('keep-options');

        $this->line("Current theme : {$current}");
        $this->line("Reset to      : {$target}");
        $this->line('Theme options : ' . ($wipeOptions ? 'wipe theme_* and css_* values' : 'keep'));

        if (! $this->option('force') && ! $this->confirm('Continue?', true)) {
            $this->info('Aborted.');

            return 0;
        }

        VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => $target]);
        $this->info("  active_template set to '{$target}'.");

        if ($wipeOptions) {
            $this->wipeThemeOptions();
        }

        $writer->write();
        $this->info('  Rewrote storage/app/vela-site.php.');

        $this->clearStaticHtml();
        $this->clearCompiledViews();

        Artisan::call('config:clear');
        Artisan::call('route:clear');
        $this->info('  Cleared config and route caches.');

        if ($this->option('regenerate')) {
            app(StaticSiteGenerator::class)->regenerateAll();
            $this->info('  Regenerated static HTML.');
        }

        $this->newLine();
        $this->info("Theme reset to '{$target}'. Pick a new one in Settings > Appearance.");

        return 0;
    }

    /**
     * Delete every theme_* / css_* config row, plus any images those
     * options uploaded into storage/theme/.
     */
    private function wipeThemeOptions(): void
    {
        $rows = VelaConfig::where('key', 'like', 'theme_%')
            ->orWhere('key', 'like', 'css_%')
            ->get(['key', 'value']);

        foreach ($rows as $row) {
            if (is_string($row->value) && str_starts_with($row->value, 'storage/theme/')) {
                Storage::disk('public')->delete(str_replace('storage/', '', $row->value));
            }
        }

        $deleted = VelaConfig::where('key', 'like', 'theme_%')
            ->orWhere('key', 'like', 'css_%')
            ->delete();

        $this->info("  Deleted {$deleted} theme_*/css_* config rows.");
    }

    private function clearStaticHtml(): void
    {
        // Shared with the chat tools, so the two cannot drift on which
        // directories count as pre-rendered pages.
        app(\VelaBuild\Core\Services\StaticSiteGenerator::class)->purgeHtml();

        $this->info('  Cleared static HTML cache.');
    }

    private function clearCompiledViews(): void
    {
        $viewPath = config('view.compiled', storage_path('framework/views'));

        if (is_dir($viewPath)) {
            foreach (glob($viewPath . '/*.php') as $file) {
                @unlink($file);
            }
        }

        $this->info('  Cleared compiled Blade views.');
    }
}
