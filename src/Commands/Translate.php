<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\TranslationStatusService;
use VelaBuild\Core\Services\Translator;

/**
 * Run the translation engine from the command line. Same engine as the
 * /admin/translations dashboard buttons, so a one-shot CI / cron pass
 * gets identical results.
 *
 *   php artisan vela:translate                    # everything missing, all locales
 *   php artisan vela:translate --locale=th        # one locale only
 *   php artisan vela:translate --scope=pages      # one surface only
 *   php artisan vela:translate --dry-run          # show what would be done
 *   php artisan vela:translate --limit=20         # cap per surface (default 200)
 */
class Translate extends Command
{
    protected $signature = 'vela:translate
                            {--locale= : Target locale (omit for all enabled locales)}
                            {--scope=all : pages|articles|categories|lang_files|all}
                            {--limit=200 : Max items per (surface, locale) pair}
                            {--dry-run : List what would change without calling the AI}
                            {--force : Re-translate items that already have translations}';

    protected $description = 'Translate missing pages, articles, categories, and lang files using the configured AI provider.';

    public function handle(TranslationStatusService $status, Translator $translator): int
    {
        $scope = $this->option('scope');
        $allowed = ['pages', 'articles', 'categories', 'lang_files', 'all'];
        if (! in_array($scope, $allowed, true)) {
            $this->error("Invalid --scope. Use one of: " . implode(', ', $allowed));
            return 1;
        }

        $surfaces = $scope === 'all' ? ['pages', 'articles', 'categories', 'lang_files'] : [$scope];

        $locale = $this->option('locale');
        $locales = $locale ? [$locale] : $status->targetLocales();
        if ($locale && ! in_array($locale, $status->targetLocales(), true)) {
            $this->error("Locale '{$locale}' is not enabled. Enable it under Settings → Languages.");
            return 1;
        }
        if ($locales === []) {
            $this->info('No target locales — only the source locale is enabled.');
            return 0;
        }

        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $force  = (bool) $this->option('force');

        $totalOk = 0;
        $totalFail = 0;

        foreach ($surfaces as $surface) {
            foreach ($locales as $loc) {
                $items = $force
                    ? $this->allItems($surface)
                    : $status->missing($surface, $loc);
                $items = array_slice($items, 0, $limit);

                if (empty($items)) {
                    $this->line("<fg=gray>· {$surface} → {$loc}: nothing to do</>");
                    continue;
                }

                $count = count($items);
                $this->line("<fg=cyan>→ {$surface} → {$loc}: {$count} item(s)" . ($dryRun ? ' (dry-run)' : '') . '</>');

                if ($dryRun) {
                    foreach ($items as $i) {
                        $this->line("    " . substr($i['label'], 0, 80));
                    }
                    continue;
                }

                $bar = $this->output->createProgressBar($count);
                $bar->start();
                foreach ($items as $i) {
                    $r = $this->translateOne($translator, $surface, $loc, $i['id']);
                    if ($r['ok']) { $totalOk++; } else { $totalFail++; $this->newLine(); $this->warn('  ! ' . $i['label'] . ': ' . $r['message']); }
                    $bar->advance();
                }
                $bar->finish();
                $this->newLine();
            }
        }

        $this->newLine();
        $this->components->info("Done — {$totalOk} translated" . ($totalFail ? ", {$totalFail} failed" : ''));
        return $totalFail > 0 ? 1 : 0;
    }

    /** All items for a surface — used when --force re-translates everything. */
    private function allItems(string $surface): array
    {
        return match ($surface) {
            'pages' => Page::whereNull('parent_id')
                ->where('slug', '!=', 'home')
                ->get(['id', 'title', 'slug'])
                ->map(fn ($p) => ['id' => $p->id, 'label' => $p->title ?: $p->slug, 'reason' => 'force'])
                ->all(),
            'articles' => Content::whereIn('status', ['scheduled', 'published'])
                ->get(['id', 'title'])
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->title, 'reason' => 'force'])
                ->all(),
            'categories' => Category::get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->name, 'reason' => 'force'])
                ->all(),
            'lang_files' => array_map(
                fn ($f) => ['id' => $f, 'label' => basename(dirname($f)) . '/' . basename($f), 'reason' => 'force'],
                app(TranslationStatusService::class)->langFileSources(),
            ),
            default => [],
        };
    }

    private function translateOne(Translator $t, string $surface, string $locale, $id): array
    {
        try {
            $r = match ($surface) {
                'pages'      => $t->translatePage(Page::findOrFail((int) $id), $locale),
                'articles'   => $t->translateContent(Content::findOrFail((int) $id), $locale),
                'categories' => $t->translateCategory(Category::findOrFail((int) $id), $locale),
                'lang_files' => $t->translateLangFile((string) $id, $locale),
            };
            return ['ok' => $r['ok'], 'message' => $r['error'] ?? ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
