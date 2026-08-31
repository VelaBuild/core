<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Models\MenuItem;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\DesignPreviewFrame;
use VelaBuild\Core\Services\StaticSiteGenerator;

/**
 * Put the site's navigation where the theme reads it from.
 *
 * A theme renders its menus through @velaMenu, and until a slot is customised
 * every site shows the same three: Home, Articles, Topics. Nothing could change
 * them from here. A design whose header reads "About  Osquery  Docs  Login
 * Create Account" therefore had nowhere to put any of it, and three rounds of
 * QA in a row went into rewriting the layout by hand trying — the most visible
 * mismatch on the page, and no legitimate way to fix it.
 */
class SetMenuTool extends BaseTool
{
    /** The slots a theme renders. */
    private const SLOTS = [
        'primary' => 'the main navigation in the header',
        'header_actions' => 'the links at the right-hand end of the header; the last one renders as a button',
        'footer_quick_links' => 'the list of links in the footer',
    ];

    /** More than this is a sitemap, not a navigation bar. */
    private const MAX_ITEMS = 12;

    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $slot = trim((string) ($parameters['slot'] ?? ''));

        if (!array_key_exists($slot, self::SLOTS)) {
            return [
                'error' => 'slot must be one of: ' . implode(', ', array_keys(self::SLOTS)) . '.',
                'slots' => self::SLOTS,
            ];
        }

        $items = $parameters['items'] ?? null;

        if (!is_array($items)) {
            return ['error' => 'items is required: a list of {label, type} — and url for type "url", or page_slug '
                . 'for type "page". Send an empty list to leave the slot deliberately blank.'];
        }

        if (count($items) > self::MAX_ITEMS) {
            return ['error' => 'That is ' . count($items) . ' items and a menu may hold at most ' . self::MAX_ITEMS
                . '. Put the rest in the footer, or on a page of their own.'];
        }

        $resolved = [];
        foreach ($items as $index => $item) {
            $label = trim((string) ($item['label'] ?? ''));
            $type = trim((string) ($item['type'] ?? 'url'));

            if ($label === '') {
                return ['error' => 'Item ' . ($index + 1) . ' has no label. Every item needs the words the design '
                    . 'shows on it.'];
            }

            if (!in_array($type, ['url', 'page', 'home', 'posts_index', 'categories_index'], true)) {
                return ['error' => 'Item "' . $label . '" has type "' . $type . '". Use "home", "posts_index", '
                    . '"categories_index", "page" with a page_slug, or "url" with a url.'];
            }

            $row = ['type' => $type, 'label' => $label, 'order_column' => $index];

            if ($type === 'page') {
                $page = Page::where('slug', trim((string) ($item['page_slug'] ?? '')))->first();

                if (!$page) {
                    return ['error' => 'Item "' . $label . '" points at a page "'
                        . ($item['page_slug'] ?? '') . '" that does not exist. Create it with create_page first, or '
                        . 'make the item type "url". A menu item leading nowhere is worse than one that is missing.'];
                }

                $row['ref_type'] = Page::class;
                $row['ref_id'] = $page->id;
            }

            if ($type === 'url') {
                $url = trim((string) ($item['url'] ?? ''));

                if ($url === '' || $url === '#') {
                    return ['error' => 'Item "' . $label . '" has no address. Give it a url, or point it at a page '
                        . 'with type "page" — a header full of links that go nowhere is what a design mockup has and '
                        . 'a working site must not.'];
                }

                $row['url'] = $url;
            }

            $resolved[] = $row;
        }

        // A design build stages its navigation rather than changing the site's:
        // someone who only wanted to see what a design would look like should
        // not find the words in their own header replaced before they have been
        // shown anything. The staged slots are what the design preview page
        // renders, and "use this as my homepage" is what moves them over.
        $scope = trim((string) ($parameters['scope'] ?? 'site'));

        if (!in_array($scope, ['site', 'design_preview'], true)) {
            return ['error' => 'scope must be "site" (the navigation visitors see) or "design_preview" '
                . '(staged for the page a design build writes onto, applied only if that design is kept).'];
        }

        $storedSlot = $scope === 'design_preview' ? DesignPreviewFrame::slot($slot) : $slot;

        $menu = Menu::firstOrCreate(['slot' => $storedSlot], ['name' => ucfirst(str_replace('_', ' ', $storedSlot))]);

        if ($actionLog) {
            $actionLog->update(['previous_state' => [
                'slot' => $slot,
                'menu_id' => $menu->id,
                'items' => $menu->items()->orderBy('order_column')->get()
                    ->map(fn ($i) => $i->only(['type', 'ref_type', 'ref_id', 'label', 'url', 'route_name', 'target', 'order_column']))
                    ->all(),
            ]]);
        }

        $menu->items()->delete();

        foreach ($resolved as $row) {
            $menu->items()->create($row);
        }

        // Every page carries the header, so every pre-rendered one is stale.
        try {
            app(StaticSiteGenerator::class)->purgeHtml();
        } catch (\Throwable $e) {
            // Nothing here is worth failing the call over.
        }

        return [
            'success' => true,
            'slot' => $slot,
            'scope' => $scope,
            'items' => count($resolved),
            'note' => $scope === 'design_preview'
                ? 'Staged for the design preview page only — the site\'s own navigation is untouched, and this '
                    . 'replaces it if the design is kept.'
                : 'The theme renders this slot on every page, and the site\'s owner can change it in '
                    . 'Appearance → Menus.',
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state ?? null;

        if (!is_array($state) || empty($state['menu_id'])) {
            throw new \RuntimeException('No previous state to undo.');
        }

        $menu = Menu::find($state['menu_id']);

        if (!$menu) {
            throw new \RuntimeException('The menu no longer exists.');
        }

        $menu->items()->delete();

        foreach ($state['items'] ?? [] as $row) {
            $menu->items()->create($row);
        }

        try {
            app(StaticSiteGenerator::class)->purgeHtml();
        } catch (\Throwable $e) {
            // As above.
        }
    }
}
