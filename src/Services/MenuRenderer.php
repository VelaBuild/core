<?php

namespace VelaBuild\Core\Services;

use Illuminate\Support\Collection;
use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Models\MenuItem;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Vela;

/**
 * Loads menu items for a given slot and renders them through a Blade
 * partial. Falls back to a sensible default (Home + published pages +
 * Articles + Topics) when the slot has not been customised — this keeps
 * pre-menu-system sites rendering correctly without admin action.
 */
class MenuRenderer
{
    public function items(string $slot): Collection
    {
        try {
            // While the design preview page is rendering, a slot the build
            // staged stands in for the site's own. Everywhere else the site's
            // navigation is untouched.
            $frame = app(\VelaBuild\Core\Services\DesignPreviewFrame::class);

            if ($frame->isActive()) {
                $staged = Menu::where('slot', \VelaBuild\Core\Services\DesignPreviewFrame::slot($slot))
                    ->with('items')
                    ->first();

                if ($staged && $staged->items->isNotEmpty()) {
                    return $staged->items;
                }
            }

            $menu = Menu::where('slot', $slot)->with('items')->first();
        } catch (\Throwable $e) {
            // No DB on static-cache deploys — fall back to defaults.
            return $this->defaultItems($slot);
        }

        // If a Menu row exists at all, treat the user's stored items as
        // authoritative — even if they've cleared everything (an empty
        // menu is a valid customisation). Only fall back to defaults
        // when the slot has never been touched.
        if ($menu) {
            return $menu->items;
        }

        return $this->defaultItems($slot);
    }

    /**
     * Render a slot to HTML through a Blade partial. The partial receives
     * `$slot`, `$items` (Collection of MenuItem-like objects with
     * resolveLabel(), resolveUrl(), isActive(), `target`), and any extra
     * variables passed in `$with`.
     */
    public function render(string $slot, ?string $view = null, array $with = []): string
    {
        $items = $this->items($slot);
        $view  = $view ?: 'vela::partials.menu-default';

        return view($view, array_merge([
            'slot'  => $slot,
            'items' => $items,
        ], $with))->render();
    }

    /**
     * Build a default item collection for known slots. Returned items are
     * unsaved MenuItem instances so the same template logic can render
     * them via resolveLabel() / resolveUrl().
     */
    protected function defaultItems(string $slot): Collection
    {
        $slotConfig = app(Vela::class)->frontMenus()->get($slot);
        if ($slotConfig === null) {
            return collect();
        }

        $items = collect();

        if (in_array($slot, ['primary', 'footer_quick_links'], true)) {
            $items->push($this->fakeItem(MenuItem::TYPE_HOME));

            try {
                $pages = Page::where('locale', app()->getLocale())
                    ->where('status', 'published')
                    ->whereNull('parent_id')
                    ->where('slug', '!=', 'home')
                    ->orderBy('order_column')
                    ->get();
                foreach ($pages as $page) {
                    $items->push($this->fakeItem(MenuItem::TYPE_PAGE, [
                        'ref_id' => $page->id,
                        'label'  => $page->title,
                    ]));
                }
            } catch (\Throwable $e) {
                // No DB — render nav with just Home / Articles / Topics.
            }

            $items->push($this->fakeItem(MenuItem::TYPE_POSTS_INDEX));
            $items->push($this->fakeItem(MenuItem::TYPE_CATEGORIES_INDEX));
        }

        return $items;
    }

    protected function fakeItem(string $type, array $attrs = []): MenuItem
    {
        $item = new MenuItem(array_merge([
            'type'   => $type,
            'target' => '_self',
        ], $attrs));
        $item->exists = false;
        return $item;
    }
}
