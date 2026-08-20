<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\AiChat\SectionImporter;
use VelaBuild\Core\Services\StaticSiteGenerator;

/**
 * Copy one section of a remote page onto a page here, as it actually looks.
 *
 * The block-by-block rebuild reproduces a page's arrangement and none of its
 * design — every section comes out wearing this site's template, which is why
 * a "copy" of a landing page reads as a distant cousin. This keeps the
 * section's own markup and its own CSS (rewritten to reach nothing outside the
 * block), brings its pictures across to local storage, and drops the result in
 * as an html block.
 */
class ImportPageSectionTool extends BaseTool
{
    /**
     * Ceiling on one page's whole stylesheet. The column is MEDIUMTEXT since
     * the migration that came with this tool; the write is checked afterwards
     * anyway, in case the site is running an older schema where TEXT would
     * silently truncate it mid-rule.
     */
    private const MAX_PAGE_CSS = 400_000;

    /** Most pictures one section may bring across. */
    private const MAX_IMAGES = 60;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $url = trim((string) ($parameters['url'] ?? ''));
        if ($url === '') {
            return ['error' => 'url is required — the page to copy the section from.'];
        }

        $page = $this->resolvePage($parameters);
        if (!$page) {
            return ['error' => 'Page not found. Pass page_id or page_slug for the page here that the section goes on.'];
        }

        $selector = $parameters['selector'] ?? null;
        $index = isset($parameters['section_index']) ? (int) $parameters['section_index'] : null;
        if (($selector === null || trim((string) $selector) === '') && !$index) {
            return ['error' => 'Say which section: pass section_index (the number browse_url action "sections" gave it) or selector (e.g. "#pricing", ".hero").'];
        }

        $includeCss = $parameters['include_css'] ?? true;
        $imported = app(SectionImporter::class)->import($url, $selector, $index, (bool) $includeCss);
        if (isset($imported['error'])) {
            return $imported;
        }

        if (!($parameters['force'] ?? false) && $furniture = $this->siteFurniture($imported)) {
            return [
                'error' => "That section is the source site's {$furniture}, which this site draws for itself on every page "
                    . 'from its own template and menus. Importing it puts a second, dead one in the middle of the page — '
                    . 'its links point back at the other site and it does not follow this site\'s pages. '
                    . 'Skip it and import the page\'s content sections instead. To change how this site\'s own '
                    . "{$furniture} looks, style it with update_custom_css or edit the template. "
                    . 'Pass force:true only if you have checked this really is page content that merely looks like a '
                    . $furniture . '.',
                'section_kind' => $furniture,
            ];
        }

        $html = $imported['html'];
        $css = $imported['css'] ?? '';

        // Pictures come across to local storage — a page that hotlinks the
        // site it was copied from breaks the day they rename a file, and
        // serves their bandwidth bill in the meantime.
        $localised = ['saved' => 0, 'failed' => 0, 'left_out' => 0];
        if (($parameters['download_images'] ?? true) && $imported['images'] !== []) {
            [$html, $css, $localised] = $this->localiseImages($html, $css, $imported['images']);
        }

        $row = PageRow::create([
            'page_id'      => $page->id,
            'name'         => 'Imported: ' . mb_substr($imported['wrapper_class'], 0, 40),
            // The section brings its own container and padding; a contained
            // row would put this site's gutters inside the other site's.
            'width'        => 'full',
            // And its own space above and below. The row's default 20px is not
            // breathing room here, it is a white seam between one section and
            // the next — visible the moment two of them sit together.
            'padding'      => '0',
            'order_column' => $parameters['order'] ?? ((int) $page->rows()->max('order_column') + 1),
        ]);

        $block = PageBlock::create([
            'page_row_id'  => $row->id,
            'type'         => 'html',
            'content'      => ['html' => $html],
            'column_index' => 0,
            'column_width' => 12,
            'order_column' => 0,
        ]);

        // Re-scope against every section imported from this page, this one
        // included. All of them share a wrapper and therefore one stylesheet,
        // and scoping to the newest section alone would strip the rules the
        // earlier ones still need.
        if ($css !== '' && !empty($imported['raw_css'])) {
            $rescoped = app(\VelaBuild\Core\Services\AiChat\CssScoper::class)->scope(
                $imported['raw_css'],
                $this->markupSharingWrapper($page, $imported['wrapper_class']),
                $imported['wrapper_class'],
                $url
            );
            if ($rescoped['css'] !== '') {
                $css = $rescoped['css'];
                $imported['css_rules_kept'] = $rescoped['rules_kept'];
                $imported['css_rules_dropped'] = $rescoped['rules_dropped'];
            }
        }

        $cssResult = $css !== '' ? $this->mergePageCss($page, $imported['wrapper_class'], $css) : null;

        $page->touch();
        app(StaticSiteGenerator::class)->removeHtml('page', $page->slug);

        if ($actionLog) {
            $actionLog->update(['previous_state' => [
                'created_row_id'   => $row->id,
                'created_block_id' => $block->id,
                'page_id'          => $page->id,
                'previous_css'     => $cssResult['previous_css'] ?? null,
            ]]);
        }

        $result = [
            'success'       => true,
            'page_id'       => $page->id,
            'row_id'        => $row->id,
            'block_id'      => $block->id,
            'wrapper_class' => $imported['wrapper_class'],
            'html_bytes'    => strlen($html),
            'images_saved'  => $localised['saved'],
            'editable_fields' => $imported['editable_fields'] ?? 0,
            'source_url'    => $url,
            'note'          => 'This section is the source page\'s own markup and styling, not page-builder blocks — '
                . 'It looks close to the original, and its wording, pictures and links are editable from the page builder '
                . 'as a plain form (the layout itself is only editable as HTML). '
                . 'Its CSS reaches nothing outside .' . $imported['wrapper_class'] . '. '
                . 'The wording and pictures belong to the source site: tell the user once to check they may use them.',
        ];

        if ($localised['failed'] > 0 || ($localised['left_out'] ?? 0) > 0) {
            $stranded = $localised['failed'] + ($localised['left_out'] ?? 0);
            $result['images_failed'] = $localised['failed'];
            $result['images_left_out'] = $localised['left_out'] ?? 0;
            $result['warning'] = $stranded . ' image(s) still point at the source site — '
                . ($localised['left_out'] ? 'the section carries more than the ' . self::MAX_IMAGES . ' this tool copies. ' : '')
                . 'Download them with download_image and swap the addresses, or leave those out.';
        }

        if ($cssResult) {
            $result += array_filter([
                'css_bytes'         => $cssResult['bytes'],
                'css_rules_kept'    => $imported['css_rules_kept'] ?? null,
                'css_rules_dropped' => $imported['css_rules_dropped'] ?? null,
                'stylesheets_read'  => $imported['stylesheets_read'] ?? null,
                'css_warning'       => $cssResult['warning'] ?? null,
            ], fn ($v) => $v !== null);
        } elseif ($includeCss) {
            // Say WHICH way it failed. "No CSS came across" on its own left
            // both the model and the user with no idea whether the stylesheets
            // were refused, empty, or filtered away — and no way to fix it.
            $result += $this->explainMissingCss($imported);
        }

        return $result;
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state) || empty($state['created_row_id'])) {
            throw new \RuntimeException('No previous state to undo.');
        }

        $row = PageRow::find($state['created_row_id']);
        if ($row) {
            $row->blocks()->delete();
            $row->delete();
        }

        $page = Page::find($state['page_id'] ?? 0);
        if ($page && array_key_exists('previous_css', $state)) {
            $page->update(['custom_css' => $state['previous_css']]);
        }
        $page?->touch();
        if ($page) {
            app(StaticSiteGenerator::class)->removeHtml('page', $page->slug);
        }
    }

    /**
     * The markup of every block on this page that carries the same wrapper —
     * i.e. every section imported from the same source page.
     */
    private function markupSharingWrapper(Page $page, string $wrapper): string
    {
        $markup = '';
        foreach ($page->rows()->with('blocks')->get() as $row) {
            foreach ($row->blocks as $block) {
                if ($block->type !== 'html') {
                    continue;
                }
                $html = (string) ($block->content['html'] ?? '');
                if (str_contains($html, $wrapper)) {
                    $markup .= $html;
                }
            }
        }

        return $markup;
    }

    /**
     * Explain an import that arrived with no styling, and say what to do next.
     */
    private function explainMissingCss(array $imported): array
    {
        $read = $imported['stylesheets_read'] ?? [];
        $failed = $imported['stylesheets_failed'] ?? [];
        $sourceBytes = (int) ($imported['css_source_bytes'] ?? 0);
        $dropped = (int) ($imported['css_rules_dropped'] ?? 0);

        $fallback = 'Meanwhile the markup is in place and will render with this site\'s styling — '
            . 'use browse_url action "design_tokens" on the same URL and write the important parts '
            . 'yourself with update_custom_css, targeting .' . ($imported['wrapper_class'] ?? '');

        if ($read === [] && $failed !== []) {
            return [
                'css_warning'        => 'Every stylesheet the page links to refused to download ('
                    . count($failed) . ' file(s)) — usually a CDN or bot check blocking a non-browser request. '
                    . 'Setting CLOUDFLARE_BROWSER_RENDERING_URL lets the CSS be read from inside the page instead. '
                    . $fallback . '.',
                'stylesheets_failed' => array_slice($failed, 0, 5),
            ];
        }

        if ($read === [] && $sourceBytes === 0) {
            return ['css_warning' => 'The page carries no stylesheet this tool could find — it may style itself '
                . 'from JavaScript after load, which only a headless browser sees. ' . $fallback . '.'];
        }

        if ($dropped > 0) {
            return ['css_warning' => 'The stylesheets were read (' . $sourceBytes . ' bytes) but every rule in them '
                . 'targets classes that are not in this section, so nothing was kept. That usually means the page '
                . 'builds its class names in JavaScript after load. ' . $fallback . '.'];
        }

        return ['css_warning' => 'No CSS came across from the source page. ' . $fallback . '.'];
    }

    /**
     * Is this section the site's own furniture rather than page content?
     *
     * Every Vela template already renders a header, a navigation menu and a
     * footer on every page, driven by this site's own pages and settings. A
     * copied one lands underneath them as a second, dead set whose links go
     * back to the site it came from — which is exactly what the page must not
     * have, and easy for a model to walk into when it imports an outline in
     * order.
     */
    private function siteFurniture(array $imported): ?string
    {
        $tag = $imported['tag'] ?? '';
        if ($tag === 'nav') {
            return 'navigation bar';
        }
        if ($tag === 'footer') {
            return 'footer';
        }
        if ($tag === 'header') {
            return 'header';
        }

        $haystack = strtolower(($imported['class'] ?? '') . ' ' . ($imported['id'] ?? ''));
        if ($haystack === ' ' || trim($haystack) === '') {
            return null;
        }

        foreach ([
            'footer'  => '/(^|[^a-z])(site-?footer|footer|colophon)([^a-z]|$)/',
            'navigation bar' => '/(^|[^a-z])(navbar|nav-bar|main-?nav|site-?nav|topbar|top-?bar|menu-?bar)([^a-z]|$)/',
            'header'  => '/(^|[^a-z])(site-?header|masthead|page-?header)([^a-z]|$)/',
        ] as $kind => $pattern) {
            if (preg_match($pattern, $haystack)) {
                return $kind;
            }
        }

        return null;
    }

    /**
     * Add this import's CSS to the page's own stylesheet, replacing any
     * earlier copy of the same import rather than stacking a second one.
     */
    private function mergePageCss(Page $page, string $wrapper, string $css): array
    {
        $previous = (string) $page->custom_css;
        $open = "/* vela-import:{$wrapper} start */";
        $close = "/* vela-import:{$wrapper} end */";

        $stripped = preg_replace(
            '/' . preg_quote($open, '/') . '.*?' . preg_quote($close, '/') . '/s',
            '',
            $previous
        ) ?? $previous;

        $merged = trim($stripped) . "\n" . $open . "\n" . $css . "\n" . $close . "\n";
        $warning = null;

        if (strlen($merged) > self::MAX_PAGE_CSS) {
            // The column is TEXT, so an oversized stylesheet is truncated by
            // the database mid-rule and takes the rest of the page's CSS with
            // it. Cut on a rule boundary here instead and say so.
            $room = self::MAX_PAGE_CSS - strlen($stripped) - strlen($open) - strlen($close) - 4;
            $cut = $room > 0 ? strrpos(substr($css, 0, $room), '}') : false;
            $css = $cut === false ? '' : substr($css, 0, $cut + 1);
            $merged = trim($stripped) . "\n" . $open . "\n" . $css . "\n" . $close . "\n";
            $warning = 'The imported CSS was larger than a page stylesheet can hold, so the tail was dropped. '
                . 'Parts of the section will look unstyled — check it with browse_url and fill the gaps with update_custom_css.';
        }

        $page->update(['custom_css' => $merged]);

        // TEXT columns on an un-migrated install truncate without erroring, so
        // read back what actually landed rather than trusting the write.
        $stored = (string) $page->fresh()?->custom_css;
        if (strlen($stored) < strlen($merged)) {
            $warning = 'The database stored only ' . strlen($stored) . ' of ' . strlen($merged)
                . ' bytes of CSS, so this page\'s styling is cut off. Run `php artisan migrate` — the pages table '
                . 'needs the migration that widens custom_css — then import the section again.';
        }

        return ['bytes' => strlen($css), 'previous_css' => $previous, 'warning' => $warning];
    }

    /**
     * Download the section's pictures and point the markup and CSS at the
     * local copies.
     *
     * @param  array<int, string> $images
     * @return array{0:string, 1:string, 2:array{saved:int, failed:int}}
     */
    private function localiseImages(string $html, string $css, array $images): array
    {
        $saved = 0;
        $failed = 0;

        // download_image takes 20 at a time, and a logo wall runs to fifty —
        // slicing at the first twenty left the rest of the section pointing at
        // the source site's server.
        foreach (array_chunk(array_slice($images, 0, self::MAX_IMAGES), 20) as $batch) {
            $result = app(DownloadImageTool::class)->execute(['urls' => $batch]);

            foreach ($result['saved'] ?? [] as $image) {
                $html = str_replace($image['source'], $image['url'], $html);
                $css = str_replace($image['source'], $image['url'], $css);
                $saved++;
            }
            $failed += count($result['failed'] ?? []);
        }

        return [$html, $css, [
            'saved'     => $saved,
            'failed'    => $failed,
            'left_out'  => max(0, count($images) - self::MAX_IMAGES),
        ]];
    }

    private function resolvePage(array $params): ?Page
    {
        if (!empty($params['page_id'])) {
            return Page::find($params['page_id']);
        }
        if (!empty($params['page_slug'])) {
            $locale = $params['locale'] ?? config('vela.primary_language', 'en');
            return Page::where('slug', $params['page_slug'])->where('locale', $locale)->first()
                ?? Page::where('slug', $params['page_slug'])->first();
        }
        return null;
    }
}
