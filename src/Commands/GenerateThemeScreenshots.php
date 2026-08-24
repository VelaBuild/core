<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\ScreenshotService;

/**
 * Capture the theme preview images shown in Settings > Appearance.
 *
 * These used to be generated from a text prompt, which produced pictures of
 * websites that did not exist: the previews showed layouts, colours and
 * sections no theme actually had. They are now photographs of the real thing —
 * each theme's own homepage, rendered from this site's content.
 *
 * The homepage view is rendered in-process with the template overridden, so
 * nothing about the live site changes while this runs and no visitor is served
 * another theme mid-capture. Stylesheets and images are absolute URLs, so the
 * site must be reachable at APP_URL for the shot to come out styled.
 */
class GenerateThemeScreenshots extends Command
{
    protected $signature = 'vela:generate-theme-screenshots
                            {--theme= : Capture one theme (default: all)}
                            {--force : Overwrite screenshots that already exist}';

    protected $description = 'Capture a preview screenshot of each theme\'s homepage with a real browser';

    public function handle(ScreenshotService $screenshots): int
    {
        if (! $screenshots->isAvailable()) {
            $this->error('No Chrome or Chromium binary found.');
            $this->line('Install Google Chrome, or point VELA_CHROME_BINARY at one.');

            return self::FAILURE;
        }

        $templates = app(\VelaBuild\Core\Vela::class)->templates()->all();
        if ($only = $this->option('theme')) {
            if (! isset($templates[$only])) {
                $this->error("Unknown theme '{$only}'. Available: " . implode(', ', array_keys($templates)));

                return self::FAILURE;
            }
            $templates = [$only => $templates[$only]];
        }

        $outputDir = dirname(__DIR__, 2) . '/public/screenshots';
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $original = config('vela.template.active');
        $captured = 0;
        $failed = 0;

        foreach ($templates as $slug => $template) {
            $outputPath = "{$outputDir}/{$slug}.jpg";

            if (file_exists($outputPath) && ! $this->option('force')) {
                $this->components->twoColumnDetail($slug, '<fg=gray>skipped (exists — use --force)</>');
                continue;
            }

            // A theme is free to ship without a default homepage — one built for
            // an existing site, say. There is nothing to preview, but that is
            // not a failure of this command.
            if (! is_file(($template['path'] ?? '') . '/home-template.json')) {
                $this->components->twoColumnDetail($slug, '<fg=yellow>no home-template.json — nothing to capture</>');
                continue;
            }

            try {
                $html = $this->renderHomepage($slug);
            } catch (\Throwable $e) {
                $this->components->twoColumnDetail($slug, '<fg=red>render failed: ' . $e->getMessage() . '</>');
                $failed++;
                continue;
            }

            // Chrome needs a document to load; a temp file keeps the capture off
            // the network and out of the router, so no auth or route is involved.
            $tmp = tempnam(sys_get_temp_dir(), 'vela-theme-') . '.html';
            file_put_contents($tmp, $html);

            try {
                // 1344px wide keeps the card in Settings > Appearance sharp on a
                // retina screen while holding each preview under ~100 KB; the
                // whole set was 7 MB as full-size PNGs.
                $screenshots->capture('file://' . $tmp, $outputPath, 1344, 82);
                $this->components->twoColumnDetail($slug, '<fg=green>captured</> <fg=gray>(' . $this->humanSize(filesize($outputPath)) . ')</>');
                $captured++;
            } catch (\Throwable $e) {
                $this->components->twoColumnDetail($slug, '<fg=red>' . $e->getMessage() . '</>');
                $failed++;
            } finally {
                @unlink($tmp);
            }
        }

        config(['vela.template.active' => $original]);

        $this->newLine();
        $this->line("Captured {$captured} screenshot(s)" . ($failed ? ", {$failed} failed" : '') . '.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Render this theme's own default homepage.
     *
     * Deliberately built from the theme's home-template.json rather than from
     * whatever homepage this site has installed: a preview is meant to show
     * what the theme looks like, and every theme sharing one site's page data
     * makes six previews of the same layout in six sets of colours.
     *
     * The rows and blocks are unsaved models — the page view only reads them,
     * and nothing about this site's content changes.
     */
    protected function renderHomepage(string $slug): string
    {
        config(['vela.template.active' => $slug]);

        $templates = app(\VelaBuild\Core\Vela::class)->templates()->all();
        $jsonPath = ($templates[$slug]['path'] ?? '') . '/home-template.json';

        if (! is_file($jsonPath)) {
            throw new \RuntimeException('theme has no home-template.json');
        }

        $rowsData = json_decode(file_get_contents($jsonPath), true);
        if (! is_array($rowsData)) {
            throw new \RuntimeException('home-template.json is not readable');
        }

        $page = new Page(['title' => 'Home', 'slug' => 'home', 'status' => 'published']);
        $page->id = 0;

        $rowId = 0;
        $blockId = 0;
        $rows = collect($rowsData)->map(function (array $rowData, int $order) use (&$rowId, &$blockId) {
            $row = new PageRow([
                'name'             => $rowData['name'] ?? null,
                'css_class'        => $rowData['css_class'] ?? null,
                'background_color' => $rowData['background_color'] ?? null,
                'background_image' => $rowData['background_image'] ?? null,
                'text_color'       => $rowData['text_color'] ?? null,
                'text_alignment'   => $rowData['text_alignment'] ?? null,
                'padding'          => $rowData['padding'] ?? null,
                'width'            => in_array($rowData['width'] ?? null, ['full', 'contained'], true) ? $rowData['width'] : 'contained',
                'order_column'     => $rowData['order'] ?? $order,
            ]);
            $row->id = ++$rowId;

            $blocks = collect($rowData['blocks'] ?? [])->map(function (array $blockData, int $blockOrder) use (&$blockId) {
                $block = new PageBlock([
                    'column_index'     => $blockData['column_index'] ?? 0,
                    'column_width'     => $blockData['column_width'] ?? 12,
                    'order_column'     => $blockData['order'] ?? $blockOrder,
                    'type'             => $blockData['type'],
                    'content'          => $blockData['content'] ?? null,
                    'settings'         => $blockData['settings'] ?? null,
                    'background_color' => $blockData['background_color'] ?? null,
                    'background_image' => $blockData['background_image'] ?? null,
                    'text_color'       => $blockData['text_color'] ?? null,
                    'text_alignment'   => $blockData['text_alignment'] ?? null,
                    'padding'          => $blockData['padding'] ?? null,
                ]);
                $block->id = ++$blockId;

                return $block;
            });

            $row->setRelation('blocks', $blocks);

            return $row;
        });

        $page->setRelation('rows', $rows);

        return view(vela_template_view('page'), compact('page'))->render();
    }

    protected function humanSize(int $bytes): string
    {
        return $bytes > 1048576
            ? round($bytes / 1048576, 1) . ' MB'
            : round($bytes / 1024) . ' KB';
    }
}
