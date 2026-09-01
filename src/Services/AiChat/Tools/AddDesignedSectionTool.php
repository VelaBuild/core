<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\AiChat\CssScoper;
use VelaBuild\Core\Services\AiChat\PageCssMerger;
use VelaBuild\Core\Services\AiChat\SectionImporter;
use VelaBuild\Core\Services\StaticSiteGenerator;

/**
 * Write one section of a page as the markup and styling a design actually
 * shows, rather than as the nearest page-builder block.
 *
 * Building a design out of blocks reproduces its wording and its running order
 * and almost none of its design: every section arrives wearing whichever shape
 * the block library has, painted from a set of theme tokens, so two designs
 * that differ in everything but palette came out looking like each other. This
 * is the same route import_page_section takes for a section copied off another
 * site — markup in an html block, its stylesheet scoped so it reaches nothing
 * else, its wording and pictures marked as fields the page builder shows as a
 * plain form — with the markup written here instead of fetched.
 *
 * It is not the tool for a listing. A grid of articles or of topics is drawn
 * from what the site holds and keeps up with it; frozen into markup it becomes
 * a picture of the site on the day it was built. Those stay real blocks.
 */
class AddDesignedSectionTool extends BaseTool
{
    /** One section's markup. Past this it is a page, not a section. */
    private const MAX_HTML = 80_000;

    /** And its stylesheet. The page's whole CSS is capped again on the way in. */
    private const MAX_CSS = 120_000;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $page = $this->resolvePage($parameters);
        if (!$page) {
            return ['error' => 'Page not found. Pass page_id or page_slug for the page this section goes on.'];
        }

        $html = trim((string) ($parameters['html'] ?? ''));
        if ($html === '') {
            return ['error' => 'html is required — the markup for this one section, as the design shows it.'];
        }

        if (strlen($html) > self::MAX_HTML) {
            return ['error' => 'That markup is ' . strlen($html) . ' bytes, and one section may be at most '
                . self::MAX_HTML . '. It is likely to be several sections at once: add them one call at a time, '
                . 'so each can be styled, checked and edited on its own.'];
        }

        $css = trim((string) ($parameters['css'] ?? ''));
        if (strlen($css) > self::MAX_CSS) {
            return ['error' => 'That stylesheet is ' . strlen($css) . ' bytes, and one section may be at most '
                . self::MAX_CSS . '. Style this section and leave the rest of the site to the theme.'];
        }

        if ($css !== '' && $error = $this->validateCssImageUrls($css)) {
            return $error;
        }

        if ($css !== '' && $error = $this->refuseUndesigned($css)) {
            return $error;
        }

        $name = trim((string) ($parameters['name'] ?? ''));
        if ($name === '') {
            return ['error' => 'name is required — what this section is: "Hero", "Features", "Pricing", "FAQ". '
                . 'It labels the row in the page builder, and it is how a later round finds the section again '
                . 'instead of adding a second one beside it.'];
        }

        // Correcting a section rewrites the one that is there. Left to add
        // another, a round of fixes puts a second hero under the first — the
        // worst kind of mismatch, and the one the QA loop is most prone to.
        $replacing = null;
        if (!empty($parameters['replace_row_id'])) {
            $replacing = PageRow::where('page_id', $page->id)
                ->where('id', (int) $parameters['replace_row_id'])
                ->first();

            if (!$replacing) {
                return ['error' => 'Row ' . (int) $parameters['replace_row_id'] . ' is not on this page. '
                    . 'Call get_page_blocks for the rows that are, or leave replace_row_id out to add a section.'];
            }
        }

        // A page with a Hero on it does not need a second one, and a round of
        // corrections reaching for this tool instead of replace_row_id is how
        // it gets one: a fix run added a whole second hero under the first and
        // reported success.
        if (!$replacing) {
            $existing = PageRow::where('page_id', $page->id)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

            if ($existing) {
                return ['error' => 'This page already has a section called "' . $name . '" (row ' . $existing->id
                    . '). To rewrite it, call this tool again with replace_row_id ' . $existing->id . '. '
                    . 'Adding a second leaves both on the page, which is a worse mismatch than whatever you were '
                    . 'correcting. If this really is a different section, give it a name of its own.'];
            }
        }

        $row = $replacing ?: PageRow::create([
            'page_id'      => $page->id,
            'name'         => mb_substr($name, 0, 60),
            // The section brings its own container and its own padding, the
            // same way an imported one does: a contained row would put this
            // site's gutters inside the design's, and the row's default 20px
            // shows as a white seam between one section and the next.
            'width'        => 'full',
            'padding'      => '0',
            'order_column' => $parameters['order'] ?? ((int) $page->rows()->max('order_column') + 1),
        ]);

        // Keyed to the row, so rewriting a section replaces its stylesheet
        // instead of leaving the old one behind under a wrapper nothing on the
        // page carries any more.
        $section = app(SectionImporter::class)->fromAuthoredMarkup($html, 'row' . $row->id);

        $refuse = function (array $error) use ($row, $replacing) {
            if (!$replacing) {
                $row->delete();
            }
            return $error;
        };

        if (isset($section['error'])) {
            return $refuse($section);
        }

        if ($furniture = $this->siteFurniture($section, $name)) {
            return $refuse([
                'error' => 'That section is a ' . $furniture . ', and this site draws its own on every page from its '
                    . 'theme and its menus. A second one lands underneath the real one as a dead copy whose links go '
                    . 'nowhere. Leave it out — the design\'s ' . $furniture . ' is the theme\'s job: set_theme_tokens '
                    . 'for its colours and type, and write_theme_file on the layout if its arrangement is wrong.',
                'section_kind' => $furniture,
            ]);
        }

        // A picture named rather than addressed renders as a broken one, and
        // the files in the design folder are what the build is reading from,
        // not pictures this site can serve.
        foreach ($section['images'] as $src) {
            if ($error = $this->validateImageResolves($src, 'an <img> in this section')) {
                return $refuse($error);
            }
        }

        // The whole point of putting markup in a block rather than in the
        // theme is that its owner can still change it. A section with nothing
        // marked is one the page builder can only offer as a box of HTML, so
        // it is no better than markup in the theme and is refused here.
        if ((int) $section['editable_fields'] === 0) {
            return $refuse([
                'error' => 'Nothing in that section is a piece of wording, a picture or a link, so the page builder '
                    . 'would have nothing to put in front of the person whose site this is — only a box of HTML. '
                    . 'Sections are written so their words live in the markup: put the design\'s real headings, '
                    . 'sentences and button labels in as text, and its pictures in as <img>.',
            ]);
        }

        $previousBlocks = null;
        if ($replacing) {
            $previousBlocks = $replacing->blocks()->get()->map(fn ($b) => [
                'type' => $b->type,
                'content' => $b->content,
            ])->all();
            $replacing->blocks()->delete();
            $replacing->update(['name' => mb_substr($name, 0, 60), 'width' => 'full', 'padding' => '0']);
        }

        $block = PageBlock::create([
            'page_row_id'  => $row->id,
            'type'         => 'html',
            'content'      => ['html' => $section['html']],
            'column_index' => 0,
            'column_width' => 12,
            'order_column' => 0,
        ]);

        $cssResult = null;
        $scoped = null;
        if ($css !== '') {
            $scoped = app(CssScoper::class)->scope($css, $section['html'], $section['wrapper_class']);
            if ($scoped['css'] !== '') {
                $cssResult = app(PageCssMerger::class)->merge(
                    $page,
                    $section['wrapper_class'],
                    $scoped['css'],
                    'vela-design'
                );
            }
        }

        $page->touch();
        app(StaticSiteGenerator::class)->removeHtml('page', $page->slug);

        if ($actionLog) {
            $actionLog->update(['previous_state' => [
                'created_row_id'   => $replacing ? null : $row->id,
                'replaced_row_id'  => $replacing?->id,
                'previous_blocks'  => $previousBlocks,
                'created_block_id' => $block->id,
                'page_id'          => $page->id,
                'previous_css'     => $cssResult['previous_css'] ?? null,
            ]]);
        }

        $result = [
            'success'         => true,
            'page_id'         => $page->id,
            'row_id'          => $row->id,
            'block_id'        => $block->id,
            'wrapper_class'   => $section['wrapper_class'],
            'html_bytes'      => strlen($section['html']),
            'editable_fields' => $section['editable_fields'],
            'replaced'        => (bool) $replacing,
            'note'            => 'This section is its own markup and styling, not page-builder blocks. Its CSS reaches '
                . 'nothing outside .' . $section['wrapper_class'] . ', so write the section\'s own class names plainly. '
                . 'Its wording, pictures and links are editable from the page builder as a plain form — including a '
                . 'link on anything that has none of its own, so do not wrap a card or a bullet in an <a href="#"> to '
                . 'leave somewhere for one to go. To correct this '
                . 'section later, call this tool again with replace_row_id ' . $row->id . '.',
        ];

        if ($css !== '' && $scoped !== null) {
            $result += array_filter([
                'css_bytes'         => $cssResult['bytes'] ?? 0,
                'css_rules_kept'    => $scoped['rules_kept'] ?? null,
                'css_rules_dropped' => $scoped['rules_dropped'] ?? null,
                'css_warning'       => $cssResult['warning'] ?? null,
            ], fn ($v) => $v !== null);

            // Every rule thrown away means the section is on the page wearing
            // nothing, and silence here reads as success.
            if ((int) ($scoped['rules_kept'] ?? 0) === 0) {
                $result['css_warning'] = 'None of that stylesheet matched anything in the markup, so the section will '
                    . 'render unstyled. The selectors must be class names that appear in the html you just sent.';
            }
        } elseif ($css === '') {
            $result['css_warning'] = 'No stylesheet came with this section, so it renders in whatever the theme gives '
                . 'plain markup. Send the section\'s CSS in the same call as its markup.';
        }

        return $result;
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;
        if (!is_array($state)) {
            throw new \RuntimeException('No previous state to undo.');
        }

        if (!empty($state['replaced_row_id'])) {
            // The section was rewritten over one that was already there, so
            // undoing it means putting that one back, not removing the row.
            $row = PageRow::find($state['replaced_row_id']);
            if ($row) {
                $row->blocks()->delete();
                foreach ($state['previous_blocks'] ?? [] as $order => $previous) {
                    PageBlock::create([
                        'page_row_id'  => $row->id,
                        'type'         => $previous['type'],
                        'content'      => $previous['content'],
                        'column_index' => 0,
                        'column_width' => 12,
                        'order_column' => $order,
                    ]);
                }
            }
        } elseif (!empty($state['created_row_id'])) {
            $row = PageRow::find($state['created_row_id']);
            if ($row) {
                $row->blocks()->delete();
                $row->delete();
            }
        } else {
            throw new \RuntimeException('No previous state to undo.');
        }

        $page = Page::find($state['page_id'] ?? 0);
        if ($page && array_key_exists('previous_css', $state) && $state['previous_css'] !== null) {
            $page->update(['custom_css' => $state['previous_css']]);
        }
        $page?->touch();
        if ($page) {
            app(StaticSiteGenerator::class)->removeHtml('page', $page->slug);
        }
    }

    /**
     * Refuse a section that has been placed but not designed.
     *
     * A build wrote five sections and the whole page's styling came to five
     * rules: display:flex on a hero, a three-column grid on the cards,
     * list-style on the topics. The arrangement was right and nothing else
     * was — no type, no colour, no spacing, no treatment on a card, no size
     * on a picture — so a design read off a magazine came out as the theme's
     * defaults in the design's running order, which reads as a build that
     * applied no styling at all.
     *
     * Layout on its own is not the design. What separates them is whether the
     * stylesheet says anything about how the section LOOKS.
     */
    private function refuseUndesigned(string $css): ?array
    {
        $properties = [];

        if (preg_match_all('/(?:^|[;{])\s*([a-z-]+)\s*:/mi', $css, $matches)) {
            $properties = array_map('strtolower', $matches[1]);
        }

        if ($properties === []) {
            return null;
        }

        // Everything that decides how a thing looks rather than where it sits.
        $design = [
            'font', 'font-size', 'font-family', 'font-weight', 'font-style',
            'color', 'background', 'background-color', 'background-image',
            'border', 'border-radius', 'border-top', 'border-bottom', 'border-left', 'border-right',
            'box-shadow', 'text-transform', 'letter-spacing', 'line-height',
            'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
            'opacity', 'text-align', 'width', 'max-width', 'height', 'aspect-ratio', 'object-fit',
        ];

        if (array_intersect($properties, $design) !== []) {
            return null;
        }

        return [
            'error' => 'That stylesheet places the section but does not design it: every declaration in it is '
                . 'layout (' . implode(', ', array_slice(array_unique($properties), 0, 6)) . ') and none of it says '
                . 'how anything looks. A section styled this way comes out in the theme\'s own type, colour and '
                . 'spacing — the design\'s arrangement wearing somebody else\'s design. Read the section off the '
                . 'picture again and write what it shows: the size and weight of its heading, the colour behind it, '
                . 'the space inside it, how a card is separated from the one beside it, how large its picture runs.',
        ];
    }

    /**
     * Is this the site's own furniture rather than a section of the page?
     *
     * Every theme renders a header, a navigation bar and a footer on every
     * page, from this site's own pages and settings. A written one sits under
     * the real one as a second, dead set — and a build did exactly that, its
     * sixth call adding a "Footer" section to a page that already had one.
     */
    private function siteFurniture(array $section, string $name): ?string
    {
        $byTag = [
            'nav' => 'navigation bar',
            'header' => 'header',
            'footer' => 'footer',
        ];

        if (isset($byTag[$section['tag'] ?? ''])) {
            return $byTag[$section['tag']];
        }

        $haystack = mb_strtolower(($section['class'] ?? '') . ' ' . $name);

        foreach (['footer' => 'footer', 'navbar' => 'navigation bar', 'site-header' => 'header'] as $needle => $what) {
            if (str_contains($haystack, $needle)) {
                return $what;
            }
        }

        return null;
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
