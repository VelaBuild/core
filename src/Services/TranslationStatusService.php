<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Facades\Schema;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\Translation;

/**
 * Computes per-locale translation completeness across every translatable
 * surface in a Vela site. Pure read service — never writes — so the
 * dashboard can call it on every page load without side-effects.
 *
 * "Coverage" definition per surface:
 *
 *   pages       → Each top-level page (parent_id IS NULL) should have a
 *                 sibling row (or itself) for each supported locale. The
 *                 source locale's row counts as 100%; missing sibling rows
 *                 are missing translations.
 *   articles    → Per Content row, presence of (id_title, id_description,
 *                 id_content) translations in vela_translations.
 *   categories  → Per Category row, presence of (id_name, id_description).
 *   lang_files  → Per source-locale lang file (en is canonical), every
 *                 string key should exist in {locale} files.
 *
 * Source locale defaults to the LaravelLocalization-configured default,
 * but can be overridden per-call so the dashboard can show "translate
 * from de" if the site originated in German.
 */
class TranslationStatusService
{
    /** Locales we consider "extra" — everything except the source locale. */
    public function targetLocales(?string $source = null): array
    {
        $source = $source ?: $this->sourceLocale();
        try {
            $all = array_keys(LaravelLocalization::getSupportedLocales());
        } catch (\Throwable $e) {
            $all = [$source];
        }
        return array_values(array_diff($all, [$source]));
    }

    public function sourceLocale(): string
    {
        try {
            return (string) LaravelLocalization::getDefaultLocale();
        } catch (\Throwable $e) {
            return (string) (config('app.locale') ?: 'en');
        }
    }

    /**
     * Top-level coverage matrix.
     * @return array<string, array<string, array{translated:int,total:int}>>
     *         shape: [surface][locale] => ['translated' => N, 'total' => M]
     */
    public function coverage(?string $source = null): array
    {
        $source = $source ?: $this->sourceLocale();
        $locales = $this->targetLocales($source);

        return [
            'pages'      => $this->pagesCoverage($locales, $source),
            'articles'   => $this->articlesCoverage($locales),
            'categories' => $this->categoriesCoverage($locales),
            'lang_files' => $this->langFilesCoverage($locales, $source),
        ];
    }

    /**
     * Item-level missing list for a specific surface + locale. Used by
     * the dashboard's "drill-in" UI and by the AI-translate buttons.
     *
     * Returns shape:
     *   [
     *     ['id' => mixed, 'label' => string, 'reason' => string],
     *     ...
     *   ]
     */
    public function missing(string $surface, string $locale, ?string $source = null): array
    {
        $source = $source ?: $this->sourceLocale();
        return match ($surface) {
            'pages'      => $this->missingPages($locale, $source),
            'articles'   => $this->missingContents($locale),
            'categories' => $this->missingCategories($locale),
            'lang_files' => $this->missingLangFiles($locale, $source),
            default => [],
        };
    }

    // ── Pages ──────────────────────────────────────────────────────────

    private function pagesCoverage(array $locales, string $source): array
    {
        $out = [];
        if (! $this->safeHasTable('vela_pages')) {
            foreach ($locales as $l) $out[$l] = ['translated' => 0, 'total' => 0];
            return $out;
        }

        $sourcePages = Page::where('locale', $source)
            ->whereNull('parent_id')
            ->where('slug', '!=', 'home')
            ->get(['id']);
        $total = $sourcePages->count();

        foreach ($locales as $loc) {
            $translated = 0;
            foreach ($sourcePages as $p) {
                if (Page::where('locale', $loc)
                    ->where(function ($q) use ($p) {
                        $q->where('id', $p->id)->orWhere('parent_id', $p->id);
                    })
                    ->exists()
                ) {
                    $translated++;
                }
            }
            $out[$loc] = ['translated' => $translated, 'total' => $total];
        }
        return $out;
    }

    private function missingPages(string $locale, string $source): array
    {
        if (! $this->safeHasTable('vela_pages')) return [];
        $sourcePages = Page::where('locale', $source)
            ->whereNull('parent_id')
            ->where('slug', '!=', 'home')
            ->get(['id', 'title', 'slug']);

        $missing = [];
        foreach ($sourcePages as $p) {
            $exists = Page::where('locale', $locale)
                ->where(function ($q) use ($p) { $q->where('id', $p->id)->orWhere('parent_id', $p->id); })
                ->exists();
            if (! $exists) {
                $missing[] = ['id' => $p->id, 'label' => $p->title ?: $p->slug, 'reason' => 'No locale row'];
            }
        }
        return $missing;
    }

    // ── Articles ──────────────────────────────────────────────────────

    private function articlesCoverage(array $locales): array
    {
        $out = [];
        if (! $this->safeHasTable('vela_contents')) {
            foreach ($locales as $l) $out[$l] = ['translated' => 0, 'total' => 0];
            return $out;
        }
        $total = Content::whereIn('status', ['scheduled', 'published'])->count();

        foreach ($locales as $loc) {
            // We use the title translation as the indicator — articles
            // usually translate as a unit, so a missing title means the
            // whole article is untranslated.
            $translated = Translation::where('lang_code', $loc)
                ->where('model_type', 'Content')
                ->where('model_key', 'like', '%_title')
                ->whereNotNull('translation')
                ->count();
            $out[$loc] = ['translated' => min($translated, $total), 'total' => $total];
        }
        return $out;
    }

    private function missingContents(string $locale): array
    {
        if (! $this->safeHasTable('vela_contents')) return [];
        $contents = Content::whereIn('status', ['scheduled', 'published'])->get(['id', 'title']);
        $haveIds = Translation::where('lang_code', $locale)
            ->where('model_type', 'Content')
            ->where('model_key', 'like', '%_title')
            ->whereNotNull('translation')
            ->pluck('model_key')
            ->map(fn ($k) => (int) explode('_', $k)[0])
            ->all();

        $missing = [];
        foreach ($contents as $c) {
            if (! in_array($c->id, $haveIds, true)) {
                $missing[] = ['id' => $c->id, 'label' => $c->title, 'reason' => 'Missing title translation'];
            }
        }
        return $missing;
    }

    // ── Categories ────────────────────────────────────────────────────

    private function categoriesCoverage(array $locales): array
    {
        $out = [];
        if (! $this->safeHasTable('vela_categories')) {
            foreach ($locales as $l) $out[$l] = ['translated' => 0, 'total' => 0];
            return $out;
        }
        $total = Category::count();

        foreach ($locales as $loc) {
            $translated = Translation::where('lang_code', $loc)
                ->where('model_type', 'Category')
                ->where('model_key', 'like', '%_name')
                ->whereNotNull('translation')
                ->count();
            $out[$loc] = ['translated' => min($translated, $total), 'total' => $total];
        }
        return $out;
    }

    private function missingCategories(string $locale): array
    {
        if (! $this->safeHasTable('vela_categories')) return [];
        $cats = Category::get(['id', 'name']);
        $haveIds = Translation::where('lang_code', $locale)
            ->where('model_type', 'Category')
            ->where('model_key', 'like', '%_name')
            ->whereNotNull('translation')
            ->pluck('model_key')
            ->map(fn ($k) => (int) explode('_', $k)[0])
            ->all();

        $missing = [];
        foreach ($cats as $c) {
            if (! in_array($c->id, $haveIds, true)) {
                $missing[] = ['id' => $c->id, 'label' => $c->name, 'reason' => 'Missing name translation'];
            }
        }
        return $missing;
    }

    // ── Lang files ────────────────────────────────────────────────────

    /**
     * Coverage for `resources/lang/{source}/*.php` translated into
     * `lang/{locale}/`. Includes both core's bundled lang files (when
     * the host hasn't published them) and the host's own files.
     *
     * The source-of-truth used is the host's lang dir if present,
     * else core's resources/lang/.
     */
    public function langFileSources(string $source = 'en'): array
    {
        $candidates = [
            base_path("lang/{$source}"),
            base_path("resources/lang/{$source}"),
            __DIR__ . "/../../resources/lang/{$source}",
        ];
        foreach ($candidates as $dir) {
            $real = realpath($dir);
            if ($real && is_dir($real)) {
                return $this->scanLangFiles($real);
            }
        }
        return [];
    }

    private function scanLangFiles(string $dir): array
    {
        $files = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iter as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $files[] = $f->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private function langFilesCoverage(array $locales, string $source): array
    {
        $out = [];
        $files = $this->langFileSources($source);

        foreach ($locales as $loc) {
            $translated = 0;
            $total = 0;
            foreach ($files as $f) {
                $sourceArr = @include $f;
                if (! is_array($sourceArr)) continue;
                $targetFile = $this->langTargetPath($f, $source, $loc);
                $targetArr  = is_file($targetFile) ? (@include $targetFile) : [];
                $targetArr  = is_array($targetArr) ? $targetArr : [];
                $stats = $this->langKeyStats($sourceArr, $targetArr);
                $total += $stats[0];
                $translated += $stats[1];
            }
            $out[$loc] = ['translated' => $translated, 'total' => $total];
        }
        return $out;
    }

    private function missingLangFiles(string $locale, string $source): array
    {
        $files = $this->langFileSources($source);
        $missing = [];
        foreach ($files as $f) {
            $sourceArr = @include $f;
            if (! is_array($sourceArr)) continue;
            $targetFile = $this->langTargetPath($f, $source, $locale);
            $targetArr  = is_file($targetFile) ? (@include $targetFile) : [];
            $targetArr  = is_array($targetArr) ? $targetArr : [];
            [$total, $have] = $this->langKeyStats($sourceArr, $targetArr);
            if ($have < $total) {
                $missing[] = [
                    'id'     => $f,
                    'label'  => str_replace([base_path() . '/', dirname(__DIR__, 2) . '/'], '', $f),
                    'reason' => "{$have}/{$total} keys translated",
                ];
            }
        }
        return $missing;
    }

    /** Counts (totalLeaves, translatedLeaves) recursively. */
    private function langKeyStats(array $source, array $target): array
    {
        $total = 0;
        $have  = 0;
        foreach ($source as $k => $v) {
            if (is_array($v)) {
                [$t, $h] = $this->langKeyStats($v, is_array($target[$k] ?? null) ? $target[$k] : []);
                $total += $t;
                $have  += $h;
                continue;
            }
            if (! is_string($v) || $v === '') continue;
            $total++;
            $tv = $target[$k] ?? null;
            if (is_string($tv) && $tv !== '') $have++;
        }
        return [$total, $have];
    }

    private function langTargetPath(string $absSourceFile, string $source, string $target): string
    {
        $sep = DIRECTORY_SEPARATOR;
        $needle = $sep . $source . $sep;
        $pos = strrpos($absSourceFile, $needle);
        if ($pos === false) {
            return dirname($absSourceFile) . $sep . $target . $sep . basename($absSourceFile);
        }
        return substr($absSourceFile, 0, $pos) . $sep . $target . $sep . substr($absSourceFile, $pos + strlen($needle));
    }

    private function safeHasTable(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
