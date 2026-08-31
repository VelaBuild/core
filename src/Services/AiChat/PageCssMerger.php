<?php

namespace VelaBuild\Core\Services\AiChat;

use VelaBuild\Core\Models\Page;

/**
 * Put one section's stylesheet into a page's own CSS, replacing the last one
 * written under the same wrapper.
 *
 * Two tools write section stylesheets — the one that copies a section from
 * another site and the one that writes a section from a design — and both need
 * the same three things: a fenced region they can rewrite without disturbing
 * anything else on the page, a cut on a rule boundary when the page's
 * stylesheet is full, and a read-back afterwards, because a `custom_css`
 * column that has not been widened by migration truncates without erroring and
 * takes the rest of the page's styling with it.
 */
class PageCssMerger
{
    /**
     * Ceiling on one page's whole stylesheet. The column is MEDIUMTEXT since
     * the migration that came with the import tool; the write is checked
     * afterwards anyway, in case the site is running an older schema.
     */
    public const MAX_PAGE_CSS = 400_000;

    /**
     * @param  string $marker  what the fence comment says: the region for a
     *                         given wrapper is replaced, others are left alone.
     * @return array{bytes:int, previous_css:string, warning:string|null}
     */
    public function merge(Page $page, string $wrapper, string $css, string $marker = 'vela-import'): array
    {
        $previous = (string) $page->custom_css;
        $open = "/* {$marker}:{$wrapper} start */";
        $close = "/* {$marker}:{$wrapper} end */";

        $stripped = preg_replace(
            '/' . preg_quote($open, '/') . '.*?' . preg_quote($close, '/') . '/s',
            '',
            $previous
        ) ?? $previous;

        $merged = trim($stripped) . "\n" . $open . "\n" . $css . "\n" . $close . "\n";
        $warning = null;

        if (strlen($merged) > self::MAX_PAGE_CSS) {
            $room = self::MAX_PAGE_CSS - strlen($stripped) - strlen($open) - strlen($close) - 4;
            $cut = $room > 0 ? strrpos(substr($css, 0, $room), '}') : false;
            $css = $cut === false ? '' : substr($css, 0, $cut + 1);
            $merged = trim($stripped) . "\n" . $open . "\n" . $css . "\n" . $close . "\n";
            $warning = 'The CSS was larger than a page stylesheet can hold, so the tail was dropped. '
                . 'Parts of the section will look unstyled — check it with browse_url and fill the gaps with update_custom_css.';
        }

        $page->update(['custom_css' => $merged]);

        $stored = (string) $page->fresh()?->custom_css;
        if (strlen($stored) < strlen($merged)) {
            $warning = 'The database stored only ' . strlen($stored) . ' of ' . strlen($merged)
                . ' bytes of CSS, so this page\'s styling is cut off. Run `php artisan migrate` — the pages table '
                . 'needs the migration that widens custom_css — then write the section again.';
        }

        return ['bytes' => strlen($css), 'previous_css' => $previous, 'warning' => $warning];
    }

    /**
     * Carry the fenced regions of a page's stylesheet over a wholesale rewrite.
     *
     * update_custom_css replaces a page's CSS entirely, which is right for CSS
     * a person wrote and wrong for these: a fix round asked to adjust the
     * navigation sent a page's worth of hand-written rules and took the
     * stylesheets of all six written sections with it. The page still loaded.
     * Every section on it came out unstyled, and the tool reported success.
     *
     * @return array{css:string, kept:int}
     */
    public function preserveFencedRegions(string $existing, string $incoming): array
    {
        $pattern = '/\/\* (vela-import|vela-design):([A-Za-z0-9_-]+) start \*\/.*?\/\* \1:\2 end \*\//s';

        if (!preg_match_all($pattern, $existing, $matches, PREG_SET_ORDER)) {
            return ['css' => $incoming, 'kept' => 0];
        }

        $kept = [];
        foreach ($matches as $match) {
            // A rewrite that carries a region itself is not overwriting it.
            if (str_contains($incoming, $match[1] . ':' . $match[2] . ' start')) {
                continue;
            }
            $kept[] = $match[0];
        }

        if (!$kept) {
            return ['css' => $incoming, 'kept' => 0];
        }

        return [
            'css'  => trim($incoming) . "\n" . implode("\n", $kept) . "\n",
            'kept' => count($kept),
        ];
    }
}
