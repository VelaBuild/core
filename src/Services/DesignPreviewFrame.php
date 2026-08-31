<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Models\VelaConfig;

/**
 * The theme and navigation a design build produced, kept apart from the site's
 * own until someone chooses to keep them.
 *
 * A build goes onto a page of its own so that nobody trying a design out has
 * their site changed underneath them. The frame did not follow that rule: the
 * build switched the whole site's theme at its third step, and once menus could
 * be set at all it changed the words in the header of every page too — a person
 * who only wanted to see what a design would look like found their live site
 * wearing it, navigation and all, before they had been shown anything.
 *
 * So the frame a build writes is stored beside the site's own and applied only
 * where the design is being looked at. `useAsHomepage` is what moves it over.
 */
class DesignPreviewFrame
{
    public const THEME_KEY = 'design_preview_template';

    /**
     * The key that lets a link ask for a page in the design's theme.
     *
     * The build has to fetch those pages itself, over HTTP and without a
     * session, to know they render at all — so this cannot be a permission.
     * A secret in the address is what both allow: the build reads it from
     * here, and a design nobody has decided to keep is not on show to anyone
     * who guesses a query string.
     */
    public const TOKEN_KEY = 'design_preview_token';

    /** Menu slots a theme renders, and the slot each is staged in. */
    public const SLOTS = ['primary', 'header_actions', 'footer_quick_links'];

    /** Set while the design preview page is being rendered. */
    private bool $active = false;

    public function activate(): void
    {
        $theme = $this->theme();

        if ($theme !== null) {
            config(['vela.template.active' => $theme]);
        }

        $this->active = true;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** The staged slot name for a slot a theme asks for. */
    public static function slot(string $slot): string
    {
        return 'design_preview_' . $slot;
    }

    public function theme(): ?string
    {
        $theme = VelaConfig::where('key', self::THEME_KEY)->value('value');

        return $theme !== null && $theme !== '' ? $theme : null;
    }

    public function setTheme(string $theme): void
    {
        VelaConfig::updateOrCreate(['key' => self::THEME_KEY], ['value' => $theme]);

        // A new token per staged theme, so a link handed out for one design
        // does not open the next one.
        VelaConfig::updateOrCreate(['key' => self::TOKEN_KEY], ['value' => bin2hex(random_bytes(16))]);
    }

    public function token(): ?string
    {
        $token = VelaConfig::where('key', self::TOKEN_KEY)->value('value');

        return $token !== null && $token !== '' ? $token : null;
    }

    public function matches(?string $token): bool
    {
        $known = $this->token();

        return $known !== null && is_string($token) && hash_equals($known, $token);
    }

    /**
     * The same address, asking for the design's theme.
     */
    public function previewUrl(string $url): string
    {
        $token = $this->token();

        if ($token === null) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . 'design_preview=' . $token;
    }

    /**
     * Move the staged frame onto the site itself.
     *
     * What it replaces is kept: someone who changes their mind about a design
     * should not find the navigation they wrote before it gone for good.
     */
    public function promote(): void
    {
        $theme = $this->theme();

        if ($theme !== null) {
            VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => $theme]);
        }

        foreach (self::SLOTS as $slot) {
            $staged = Menu::where('slot', self::slot($slot))->with('items')->first();

            if (!$staged || $staged->items->isEmpty()) {
                continue;
            }

            $live = Menu::firstOrCreate(['slot' => $slot], ['name' => ucfirst(str_replace('_', ' ', $slot))]);

            $superseded = Menu::firstOrCreate(
                ['slot' => 'superseded_' . $slot],
                ['name' => 'Superseded ' . str_replace('_', ' ', $slot)]
            );
            $superseded->items()->delete();

            foreach ($live->items()->orderBy('order_column')->get() as $item) {
                $superseded->items()->create($this->copyable($item));
            }

            $live->items()->delete();

            foreach ($staged->items()->orderBy('order_column')->get() as $item) {
                $live->items()->create($this->copyable($item));
            }
        }

        try {
            app(SiteConfigWriter::class)->write();
            SiteConfigWriter::apply();
            app(StaticSiteGenerator::class)->purgeHtml();
        } catch (\Throwable $e) {
            // The frame is moved either way; the caches rebuild on their own.
        }
    }

    /** @return array<string, mixed> */
    private function copyable($item): array
    {
        return [
            'type' => $item->type,
            'ref_type' => $item->ref_type,
            'ref_id' => $item->ref_id,
            'label' => $item->label,
            'url' => $item->url,
            'route_name' => $item->route_name,
            'target' => $item->target,
            'order_column' => $item->order_column,
        ];
    }
}
