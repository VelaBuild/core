<?php

namespace VelaBuild\Core\Services\Blocks;

use VelaBuild\Core\Services\DesignTokens;

/**
 * A call to action's heading and settings, made safe for the page.
 */
class Cta
{
    public const LAYOUTS = ['stacked', 'split', 'card'];
    public const SIZES = ['normal', 'compact', 'large'];

    /** Tags a heading may carry, for the editorial italic or a bold word. */
    private const EMPHASIS = 'em|strong|i|b';

    /**
     * The heading as HTML: every character escaped, then the emphasis tags put
     * back bare.
     *
     * strip_tags() with an allow-list was used before, and it keeps the
     * attributes of the tags it allows — `<em onmouseover="…">` reached the
     * page as written. Headings arrive from the editor, from the AI, and from
     * sections copied off other sites, so nothing about their tags is trusted.
     */
    public static function heading(string $heading): string
    {
        $bare = preg_replace('#<(/?)(' . self::EMPHASIS . ')\b[^>]*>#i', '<$1$2>', strip_tags($heading, '<em><strong><i><b>'));
        $escaped = e((string) $bare);

        return (string) preg_replace('#&lt;(/?)(' . self::EMPHASIS . ')&gt;#i', '<$1$2>', $escaped);
    }

    public static function settings(array $raw): array
    {
        $pick = fn (string $key, array $allowed) => in_array($raw[$key] ?? null, $allowed, true) ? $raw[$key] : $allowed[0];

        return [
            'text_alignment' => $pick('text_alignment', ['center', 'left', 'right']),
            'layout'         => $pick('layout', self::LAYOUTS),
            'size'           => $pick('size', self::SIZES),
            // Empty is the theme's own look for the block, as every call to
            // action saved before there was a choice has.
            'background'     => trim((string) ($raw['background'] ?? '')),
            'button_style'   => $pick('button_style', ['solid', 'pill', 'outline']),
            'button_color'   => trim((string) ($raw['button_color'] ?? '')),
        ];
    }

    /**
     * The block's classes and inline custom properties for its colours. Each
     * colour is checked by DesignTokens and paired with an ink that reads on it.
     *
     * @return array{classes: string[], style: string}
     */
    public static function look(array $s): array
    {
        $classes = ['block-cta', 'block-cta--' . $s['layout'], 'block-cta--' . $s['size'], 'block-cta--align-' . $s['text_alignment'], 'block-cta--btn-' . $s['button_style']];
        $style = 'text-align:' . $s['text_alignment'] . ';';

        if ($bg = DesignTokens::colour($s['background'])) {
            $classes[] = 'has-cta-bg';
            if ($s['background'] === 'token:accent') {
                $classes[] = 'is-accent-bg';
            }
            $style .= '--cta-bg:' . $bg . ';';
            if ($ink = DesignTokens::inkFor($s['background'])) {
                $style .= '--cta-ink:' . $ink . ';';
            }
        }
        if ($btn = DesignTokens::colour($s['button_color'])) {
            $classes[] = 'has-btn-color';
            $style .= '--cta-btn-bg:' . $btn . ';';
            if ($btnInk = DesignTokens::inkFor($s['button_color'])) {
                $style .= '--cta-btn-ink:' . $btnInk . ';';
            }
        }

        return ['classes' => $classes, 'style' => $style];
    }
}
