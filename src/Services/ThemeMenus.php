<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Models\Menu;

/**
 * Navigation that belongs to one theme rather than to the whole site.
 *
 * A site has one set of pages and one set of articles whatever it is wearing —
 * that much is settled, and storing content per theme would mean a page
 * written under one theme vanishing under another. Its NAVIGATION is not so
 * clearly the site's: a design arrives with its own words across the header,
 * and writing them into the site's only menu is how "About / Osquery / Docs"
 * ended up in the header of a theme that had never heard of Osquery, with the
 * site's own menu gone for good.
 *
 * WordPress draws the line in the same place — posts and pages are shared,
 * `nav_menu_locations` is a theme mod — and this is that idea in Vela's terms:
 * a menu row whose slot is "<theme>::<slot>" belongs to that theme, and a
 * plain "<slot>" is the site's, used by every theme that has none of its own.
 *
 * Nothing had to be migrated for this. A site that has only ever had plain
 * slots keeps rendering exactly what it did.
 */
class ThemeMenus
{
    /** What separates a theme from the slot it is scoping. */
    public const SEPARATOR = '::';

    /** The slot a menu is stored under when it belongs to one theme. */
    public static function slot(string $theme, string $slot): string
    {
        $theme = trim($theme);

        return $theme === '' ? $slot : $theme . self::SEPARATOR . $slot;
    }

    /** The theme rendering right now, which is not always the site's own. */
    public static function currentTheme(): string
    {
        return (string) config('vela.template.active', '');
    }

    /** Does this theme keep its own navigation for this slot? */
    public static function has(string $theme, string $slot): bool
    {
        return Menu::where('slot', self::slot($theme, $slot))->exists();
    }

    /**
     * Give this theme a menu of its own, starting from what it shows now.
     *
     * Copied rather than moved: the site's menu is what every other theme is
     * using, and taking it away to give one theme its own is not what anybody
     * asked for by pressing "this theme's own".
     */
    public static function claim(string $theme, string $slot): Menu
    {
        $scoped = Menu::firstOrCreate(
            ['slot' => self::slot($theme, $slot)],
            ['label' => ucwords(str_replace(['-', '_'], ' ', $slot))]
        );

        if ($scoped->items()->exists()) {
            return $scoped;
        }

        $shared = Menu::where('slot', $slot)->with('items')->first();

        foreach ($shared?->items()->orderBy('order_column')->get() ?? [] as $item) {
            $copy = $item->replicate(['id']);
            $copy->menu_id = $scoped->id;
            $copy->save();
        }

        return $scoped;
    }

    /** Put this theme back on the site's shared navigation. */
    public static function release(string $theme, string $slot): void
    {
        $scoped = Menu::where('slot', self::slot($theme, $slot))->first();

        if (!$scoped) {
            return;
        }

        $scoped->items()->delete();
        $scoped->delete();
    }
}
