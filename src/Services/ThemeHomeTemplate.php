<?php

namespace VelaBuild\Core\Services;

use VelaBuild\Core\Models\Page;

/**
 * A theme's own example homepage, written from a page.
 *
 * Every theme that ships with Vela carries a `home-template.json`, and that
 * file is what Settings → Appearance offers to install, what the standing
 * "this theme has a homepage of its own" panel points at, and what
 * vela:generate-theme-screenshots photographs for the theme's card. A theme
 * written by a design build carried none of it, so a generated theme could
 * not put its own homepage back, could not offer it as a new page, and showed
 * a grey palette icon in the picker where the others show themselves.
 *
 * The layout alone is not enough for one of these. A shipped theme's example
 * homepage is built from blocks the theme already styles; a built one is
 * markup with a stylesheet of its own, which lives on the page rather than in
 * the theme — so the stylesheet is written out beside the layout and put back
 * with it. Without that the rows would come back wearing nothing.
 */
class ThemeHomeTemplate
{
    public const LAYOUT = 'home-template.json';
    public const STYLESHEET = 'home-template.css';

    /**
     * Where a theme's example homepage lives, if this site may write it.
     *
     * Only a theme in the site's own resources. The ones that ship with Vela
     * live inside the package: writing there would be modifying the vendor
     * directory, and the next update would take it away again.
     */
    public function pathFor(string $theme): ?string
    {
        $theme = trim($theme);

        if ($theme === '' || !preg_match('/^[a-z0-9_-]+$/i', $theme)) {
            return null;
        }

        $dir = resource_path('views/templates/' . $theme);

        return is_dir($dir) ? $dir : null;
    }

    /** Whether this theme has an example homepage to install. */
    public function has(string $theme): bool
    {
        $dir = $this->pathFor($theme);

        return $dir !== null && is_file($dir . '/' . self::LAYOUT);
    }

    /**
     * Keep this page as the theme's example homepage.
     *
     * @return bool whether anything was written
     */
    public function writeFrom(Page $page, string $theme): bool
    {
        $dir = $this->pathFor($theme);

        if ($dir === null) {
            return false;
        }

        $rows = $this->rowsOf($page);

        // A page with nothing on it is not an example of anything, and would
        // replace a real one with an empty file.
        if ($rows === []) {
            return false;
        }

        file_put_contents(
            $dir . '/' . self::LAYOUT,
            json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $css = (string) $page->custom_css;
        $stylesheet = $dir . '/' . self::STYLESHEET;

        if (trim($css) !== '') {
            file_put_contents($stylesheet, $css);
        } elseif (is_file($stylesheet)) {
            // The page has none now, so neither has the example: a stylesheet
            // left from a previous save would dress the next layout in the
            // last one's rules.
            unlink($stylesheet);
        }

        return true;
    }

    /** The stylesheet that belongs with a theme's example homepage, if any. */
    public function stylesheetFor(string $theme): ?string
    {
        $dir = $this->pathFor($theme);

        if ($dir === null || !is_file($dir . '/' . self::STYLESHEET)) {
            return null;
        }

        $css = (string) file_get_contents($dir . '/' . self::STYLESHEET);

        return trim($css) === '' ? null : $css;
    }

    /**
     * The page as the shape installHomepage() reads back.
     *
     * Every column the installer looks for, so what comes back is what was
     * there — a row's width and padding decide as much about a design as its
     * blocks do.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsOf(Page $page): array
    {
        $rows = [];

        foreach ($page->rows()->with('blocks')->orderBy('order_column')->get() as $order => $row) {
            $blocks = [];

            foreach ($row->blocks()->orderBy('order_column')->get() as $blockOrder => $block) {
                $blocks[] = array_filter([
                    'column_index' => (int) $block->column_index,
                    'column_width' => (int) $block->column_width,
                    'order' => (int) ($block->order_column ?? $blockOrder),
                    'type' => (string) $block->type,
                    'content' => $block->content,
                    'settings' => $block->settings,
                    'background_color' => $block->background_color,
                    'background_image' => $block->background_image,
                    'text_color' => $block->text_color,
                    'text_alignment' => $block->text_alignment,
                    'padding' => $block->padding,
                ], fn ($value) => $value !== null);
            }

            $rows[] = array_filter([
                'name' => $row->name,
                'css_class' => $row->css_class,
                'background_color' => $row->background_color,
                'background_image' => $row->background_image,
                'text_color' => $row->text_color,
                'text_alignment' => $row->text_alignment,
                'padding' => $row->padding,
                'width' => $row->width,
                'order' => (int) ($row->order_column ?? $order),
                'blocks' => $blocks,
            ], fn ($value) => $value !== null);
        }

        return $rows;
    }
}
