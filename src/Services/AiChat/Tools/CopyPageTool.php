<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use Illuminate\Support\Str;
use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\AiChat\PageSectionExtractor;
use VelaBuild\Core\Services\AiChat\SectionImporter;
use VelaBuild\Core\Services\StaticSiteGenerator;

/**
 * Copy a whole page from another site in one call.
 *
 * Asked to copy a page, the model would read the outline and then rebuild it
 * out of page-builder blocks — a hero here, an icon_box there — because that
 * was the first route the instructions offered. The result carried the right
 * words in roughly the right order and looked nothing like the original, while
 * import_page_section, which does look like the original, went unused. One
 * page came out as a "Skip to content" text block followed by the source
 * site's navigation menu rendered as feature boxes.
 *
 * So the whole job is one tool: read the outline, drop the furniture, and
 * import every content section in order. Nothing is left to decide.
 */
class CopyPageTool extends BaseTool
{
    /** Sections past this are left to a follow-up call. */
    private const MAX_SECTIONS = 15;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $url = FetchUrlTool::resolveAgainstThisSite(trim((string) ($parameters['url'] ?? '')));
        if ($url === '') {
            return ['error' => 'url is required — the page to copy.'];
        }

        $outline = $this->outline($url);
        if (isset($outline['error'])) {
            return $outline;
        }
        if ($outline['sections'] === []) {
            return ['error' => "Could not find any sections on {$url}. If the page builds itself in JavaScript, set CLOUDFLARE_BROWSER_RENDERING_URL so it can be rendered first."];
        }

        [$page, $createdPageId] = $this->resolveOrCreatePage($parameters, $url);
        if ($page === null) {
            return ['error' => 'Page not found. Pass page_id or page_slug for an existing page, or title to create a new one.'];
        }

        $importer = new ImportPageSectionTool();
        $imported = [];
        $skipped = [];
        $failed = [];
        $createdRows = [];

        foreach ($outline['sections'] as $section) {
            if (count($imported) >= (int) ($parameters['max_sections'] ?? self::MAX_SECTIONS)) {
                $skipped[] = ['index' => $section['index'], 'reason' => 'section limit reached — call again with a higher max_sections'];
                continue;
            }

            if ($reason = $this->skipReason($section)) {
                $skipped[] = ['index' => $section['index'], 'reason' => $reason, 'heading' => $section['heading'] ?? null];
                continue;
            }

            $result = $importer->execute([
                'url'             => $url,
                'page_id'         => $page->id,
                'section_index'   => $section['index'],
                'include_css'     => $parameters['include_css'] ?? true,
                'download_images' => $parameters['download_images'] ?? true,
            ]);

            if (!empty($result['error'])) {
                // The importer refuses the source site's own header, nav and
                // footer; that is a skip, not a failure.
                if (!empty($result['section_kind'])) {
                    $skipped[] = ['index' => $section['index'], 'reason' => "the source site's " . $result['section_kind']];
                    continue;
                }
                $failed[] = ['index' => $section['index'], 'error' => Str::limit($result['error'], 140)];
                continue;
            }

            $createdRows[] = $result['row_id'];
            $imported[] = [
                'index'    => $section['index'],
                'heading'  => $section['heading'] ?? null,
                'row_id'   => $result['row_id'],
                'images'   => $result['images_saved'] ?? 0,
                'fields'   => $result['editable_fields'] ?? 0,
            ];
        }

        if ($imported === []) {
            return [
                'error'   => 'Nothing could be copied from that page: every section was either site furniture or failed to import.',
                'skipped' => $skipped,
                'failed'  => $failed,
            ];
        }

        $page->touch();
        app(StaticSiteGenerator::class)->removeHtml('page', $page->slug);

        if ($actionLog) {
            $actionLog->update(['previous_state' => [
                'created_page_id' => $createdPageId,
                'created_row_ids' => $createdRows,
                'page_id'         => $page->id,
            ]]);
        }

        return [
            'success'        => true,
            'page_id'        => $page->id,
            'page_slug'      => $page->slug,
            'page_status'    => $page->status,
            'source_url'     => $url,
            'sections_found' => count($outline['sections']),
            'sections_copied' => count($imported),
            'copied'         => $imported,
            'skipped'        => $skipped ?: null,
            'failed'         => $failed ?: null,
            'editable_fields' => array_sum(array_column($imported, 'fields')),
            'images_saved'   => array_sum(array_column($imported, 'images')),
            'note'           => 'Each section came across with the source page\'s own markup and styling, and its wording, '
                . 'pictures and links are editable in the page builder as a plain form. '
                . ($page->status === 'draft' ? 'The page is a draft — it 404s for visitors until published. ' : '')
                . 'Tell the user once that the wording and pictures belong to the source site and they should check they may use them.',
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state)) {
            throw new \RuntimeException('No previous state to undo.');
        }

        foreach ($state['created_row_ids'] ?? [] as $rowId) {
            $row = PageRow::find($rowId);
            if ($row) {
                $row->blocks()->delete();
                $row->delete();
            }
        }

        $page = Page::find($state['page_id'] ?? 0);

        // A page this call created goes away entirely; one that already
        // existed keeps everything that was on it before.
        if (!empty($state['created_page_id'])) {
            Page::find($state['created_page_id'])?->delete();
            return;
        }

        if ($page) {
            $page->touch();
            app(StaticSiteGenerator::class)->removeHtml('page', $page->slug);
        }
    }

    /** @return array{sections:array}|array{error:string} */
    private function outline(string $url): array
    {
        $result = app(BrowseUrlTool::class)->execute(['url' => $url, 'action' => 'sections']);

        if (!empty($result['error'])) {
            return ['error' => $result['error']];
        }

        return ['sections' => $result['sections'] ?? []];
    }

    /**
     * Which sections are not part of the page's content.
     *
     * The outline already suggests skipping navigation, headers and footers;
     * this also drops the scraps around them — a "Skip to content" link, a
     * cookie strip — which were being rebuilt as text blocks at the top of
     * the copied page.
     */
    private function skipReason(array $section): ?string
    {
        if (str_starts_with((string) ($section['suggested_block'] ?? ''), 'skip')) {
            return 'site navigation, header or footer — this site draws its own';
        }

        $chars = (int) ($section['text_chars'] ?? 0);
        $images = (int) ($section['image_count'] ?? 0);

        // A heading makes it a section however short it is: a page whose hero
        // is one line — "Scale your app, control your costs" — had its title
        // dropped as too small and the copy opened halfway down the page.
        $hasHeading = trim((string) ($section['heading'] ?? '')) !== '';
        if (!$hasHeading && $chars < 40 && $images === 0 && empty($section['has_form'])) {
            return 'too little in it to be a section (' . $chars . ' characters)';
        }

        $haystack = strtolower(($section['heading'] ?? '') . ' ' . ($section['text'] ?? ''));
        if (preg_match('/^(skip to (main )?content|skip navigation|accept (all )?cookies)/', trim($haystack))) {
            return 'an accessibility or cookie strip, not page content';
        }

        return null;
    }

    /** @return array{0:?Page, 1:?int} the page to build on, and its id if this call created it */
    private function resolveOrCreatePage(array $params, string $url): array
    {
        if (!empty($params['page_id'])) {
            return [Page::find($params['page_id']), null];
        }

        $locale = $params['locale'] ?? config('vela.primary_language', 'en');

        if (!empty($params['page_slug'])) {
            $page = Page::where('slug', $params['page_slug'])->where('locale', $locale)->first()
                ?? Page::where('slug', $params['page_slug'])->first();
            if ($page) {
                return [$page, null];
            }
        }

        $title = trim((string) ($params['title'] ?? ''));
        if ($title === '') {
            // The address usually names the page better than "Imported page".
            $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
            $title = $path === '' ? 'Home' : Str::title(str_replace(['-', '/'], ' ', $path));
        }

        $slug = Str::slug($params['page_slug'] ?? $params['slug'] ?? $title) ?: 'copied-page';
        $base = $slug;
        $suffix = 2;
        while (Page::where('slug', $slug)->where('locale', $locale)->exists()) {
            $slug = $base . '-' . $suffix++;
        }

        $page = Page::create([
            'title'  => $title,
            'slug'   => $slug,
            // Never published by this call: a copy of someone else's page
            // going straight onto the public internet is not a decision a
            // tool gets to make.
            'status' => 'draft',
            'locale' => $locale,
        ]);

        return [$page, $page->id];
    }
}
