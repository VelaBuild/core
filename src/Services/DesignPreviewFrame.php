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

    /** Which design the staged theme was written for. */
    public const DESIGN_KEY = 'design_preview_design';

    /**
     * The theme the site was wearing before a design was kept.
     *
     * Written by promote() and read by demote(). Without it, changing your mind
     * about a design meant knowing which of a dozen themes had been yours and
     * picking it out of a list by hand — the homepage and the menus could be
     * put back and the theme could not, so the site came back half restored.
     */
    public const SUPERSEDED_THEME_KEY = 'design_superseded_template';

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

    public function setTheme(string $theme, ?string $designKey = null): void
    {
        VelaConfig::updateOrCreate(['key' => self::THEME_KEY], ['value' => $theme]);

        // Which design this theme was written for. A theme name is generated
        // from the design, but the staged theme outlives the run that made it:
        // a corporate design handed to a rig still holding an editorial theme
        // from yesterday adopted it, and every colour and typeface afterwards
        // was an attempt to bend a magazine into a corporate site.
        // Left alone when the caller does not know it. The tool that stages a
        // theme is called by the model and has no idea which design is being
        // built; the command stamps the key once per run, and this must not
        // wipe it out from under that.
        if ($designKey !== null) {
            VelaConfig::updateOrCreate(['key' => self::DESIGN_KEY], ['value' => $designKey]);
        }

        // A new token per staged theme, so a link handed out for one design
        // does not open the next one.
        VelaConfig::updateOrCreate(['key' => self::TOKEN_KEY], ['value' => bin2hex(random_bytes(16))]);
    }

    /** The design the staged theme was written for, if it said. */
    public function designKey(): ?string
    {
        $key = VelaConfig::where('key', self::DESIGN_KEY)->value('value');

        return $key !== null && $key !== '' ? $key : null;
    }

    /**
     * Forget the staged theme, so a build has to write one of its own.
     *
     * Called when the theme on the peg was written for a different design.
     * Leaving it there is worse than having none: the build reads a theme as
     * already done and spends its rounds bending it instead of writing one.
     */
    public function forgetTheme(): void
    {
        VelaConfig::where('key', self::THEME_KEY)->delete();
        VelaConfig::where('key', self::DESIGN_KEY)->delete();
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
            // Noted before it is replaced, and only when it is really changing:
            // pressing "use this as my homepage" twice must not record the
            // design's own theme as the thing to go back to.
            $before = (string) (VelaConfig::where('key', 'active_template')->value('value')
                ?: config('vela.template.active', ''));

            if ($before !== '' && $before !== $theme) {
                VelaConfig::updateOrCreate(
                    ['key' => self::SUPERSEDED_THEME_KEY],
                    ['value' => $before]
                );
            }

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

    /**
     * Put back the theme and navigation a kept design replaced.
     *
     * The mirror of promote(), and it exists because "try a design" is only
     * safe if it can be untried. What it restores is the FRAME; the homepage
     * itself is a page, and the caller swaps that back.
     *
     * @return bool false when there is nothing to go back to
     */
    public function demote(): bool
    {
        $previous = trim((string) VelaConfig::where('key', self::SUPERSEDED_THEME_KEY)->value('value'));

        if ($previous === '') {
            return false;
        }

        VelaConfig::updateOrCreate(['key' => 'active_template'], ['value' => $previous]);
        VelaConfig::where('key', self::SUPERSEDED_THEME_KEY)->delete();

        foreach (self::SLOTS as $slot) {
            $superseded = Menu::where('slot', 'superseded_' . $slot)->with('items')->first();

            if (!$superseded || $superseded->items->isEmpty()) {
                continue;
            }

            $live = Menu::firstOrCreate(['slot' => $slot], ['name' => ucfirst(str_replace('_', ' ', $slot))]);
            $live->items()->delete();

            foreach ($superseded->items()->orderBy('order_column')->get() as $item) {
                $live->items()->create($this->copyable($item));
            }

            // Emptied rather than kept: going back twice would otherwise put
            // the same old menu back over something else.
            $superseded->items()->delete();
        }

        try {
            app(SiteConfigWriter::class)->write();
            SiteConfigWriter::apply();
            app(StaticSiteGenerator::class)->purgeHtml();
        } catch (\Throwable $e) {
            // The frame is moved either way; the caches rebuild on their own.
        }

        return true;
    }

    /** Is there a theme and navigation to go back to? */
    public function canDemote(): bool
    {
        return trim((string) VelaConfig::where('key', self::SUPERSEDED_THEME_KEY)->value('value')) !== '';
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
