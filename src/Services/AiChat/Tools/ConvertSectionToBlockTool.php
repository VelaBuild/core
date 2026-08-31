<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\AiChat\PageCssMerger;
use VelaBuild\Core\Services\AiChat\SectionToBlock;
use VelaBuild\Core\Services\StaticSiteGenerator;

/**
 * Turn a written section into a real block, where a block can carry it.
 *
 * A design is built as written sections because that is what makes it look
 * like the design; what a block adds is the ability to restructure it, which
 * is worth having wherever it costs nothing. Which sections those are is not
 * knowable in advance — deciding before building swung the whole page one way
 * one run and the other way the next — so it is decided here, against the
 * section that was actually built, and checked afterwards by looking at the
 * page.
 *
 * Nothing about it is one-way. The markup and the section's stylesheet are
 * kept, so a conversion that turns out to look worse is put back exactly as it
 * was.
 */
class ConvertSectionToBlockTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $rowId = (int) ($parameters['row_id'] ?? 0);
        $row = $rowId ? PageRow::find($rowId) : null;

        if (!$row) {
            return ['error' => 'row_id is required — the row holding the written section, from get_page_blocks.'];
        }

        $blocks = $row->blocks()->orderBy('order_column')->get();

        if ($blocks->count() !== 1 || $blocks->first()->type !== 'html') {
            return ['error' => 'Row ' . $rowId . ' does not hold a written section — it holds '
                . ($blocks->isEmpty() ? 'nothing' : $blocks->pluck('type')->implode(', '))
                . '. Only a row with a single html block can be converted.'];
        }

        $html = (string) ($blocks->first()->content['html'] ?? '');
        $type = trim((string) ($parameters['type'] ?? ''));

        if ($type === '') {
            return [
                'error' => 'type is required — the block this section should become.',
                'types_a_section_can_become' => SectionToBlock::CONVERTIBLE,
            ];
        }

        $shaped = app(SectionToBlock::class)->content($html, $type);

        if (isset($shaped['error'])) {
            return $shaped;
        }

        // Wording that no part of the block can hold would simply vanish from
        // the page, and a build that quietly drops the design's sentences is
        // worse than one that leaves a section as markup.
        if ($shaped['unused'] !== [] && !($parameters['force'] ?? false)) {
            return [
                'error' => 'A "' . $type . '" block has nowhere to put ' . count($shaped['unused'])
                    . ' piece(s) of this section\'s wording, so converting it would drop them from the page: "'
                    . implode('", "', array_map(fn ($t) => mb_substr($t, 0, 60), array_slice($shaped['unused'], 0, 3)))
                    . '". Leave the section as it is, or pass force:true if that wording really is not wanted.',
                'would_be_dropped' => $shaped['unused'],
            ];
        }

        $page = Page::find($row->page_id);

        if (!$page) {
            return ['error' => 'The page this row belongs to no longer exists.'];
        }

        if ($actionLog) {
            $actionLog->update(['previous_state' => [
                'row_id' => $row->id,
                'page_id' => $page->id,
                'html' => $html,
                'previous_css' => (string) $page->custom_css,
                'row' => $row->only(['name', 'width', 'padding']),
            ]]);
        }

        $row->blocks()->delete();

        $block = PageBlock::create(array_filter([
            'page_row_id' => $row->id,
            'type' => $shaped['type'],
            'content' => $shaped['content'],
            'background_image' => $shaped['background_image'],
            'column_index' => 0,
            'column_width' => 12,
            'order_column' => 0,
        ], fn ($v) => $v !== null));

        // The section's own stylesheet went with it: a block is painted by the
        // theme, and rules left behind under a wrapper nothing carries any
        // more are dead weight on every page load.
        $wrapper = preg_match('/class="(vela-design-[a-z0-9]+)"/', $html, $m) ? $m[1] : null;

        if ($wrapper) {
            app(PageCssMerger::class)->merge($page, $wrapper, '', 'vela-design');
        }

        // A block brings the theme's own spacing, so the row must stop
        // pretending it is holding a section that supplies its own.
        $row->update(['width' => 'contained', 'padding' => '']);

        $page->touch();
        app(StaticSiteGenerator::class)->removeHtml('page', $page->slug);

        return [
            'success' => true,
            'row_id' => $row->id,
            'block_id' => $block->id,
            'type' => $shaped['type'],
            'note' => 'The section is now a ' . $shaped['type'] . ' block, so its owner can restructure it and not '
                . 'only reword it — and it is painted by the theme rather than by its own stylesheet, so it will not '
                . 'look identical. Photograph the page and compare it with the design: if the section has moved away '
                . 'from it, write it again with add_designed_section and replace_row_id ' . $row->id . '.',
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;

        if (!is_array($state) || empty($state['row_id'])) {
            throw new \RuntimeException('No previous state to undo.');
        }

        $row = PageRow::find($state['row_id']);

        if (!$row) {
            throw new \RuntimeException('The row no longer exists.');
        }

        $row->blocks()->delete();

        PageBlock::create([
            'page_row_id' => $row->id,
            'type' => 'html',
            'content' => ['html' => $state['html']],
            'column_index' => 0,
            'column_width' => 12,
            'order_column' => 0,
        ]);

        $row->update($state['row'] ?? ['width' => 'full', 'padding' => '0']);

        $page = Page::find($state['page_id'] ?? 0);

        if ($page) {
            $page->update(['custom_css' => $state['previous_css'] ?? '']);
            $page->touch();
            app(StaticSiteGenerator::class)->removeHtml('page', $page->slug);
        }
    }
}
