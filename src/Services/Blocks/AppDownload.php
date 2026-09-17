<?php

namespace VelaBuild\Core\Services\Blocks;

use VelaBuild\Core\Services\DesignTokens;

/**
 * An app download block's store links and settings, made safe for the page.
 */
class AppDownload
{
    public const LAYOUTS = ['stacked', 'split', 'card'];
    public const SIZES = ['normal', 'compact', 'large'];
    public const BADGES = ['dark', 'light', 'outline'];

    public static function settings(array $raw): array
    {
        $pick = fn (string $key, array $allowed) => in_array($raw[$key] ?? null, $allowed, true) ? $raw[$key] : $allowed[0];

        return [
            // One of three, never the stored string: it reaches two style
            // attributes, and `center;position:fixed;inset:0` once covered the
            // whole page.
            'text_alignment' => $pick('text_alignment', ['center', 'left', 'right']),
            'layout'         => $pick('layout', self::LAYOUTS),
            'size'           => $pick('size', self::SIZES),
            // Empty is the theme's own look, as every block saved before there
            // was a choice has.
            'background'     => trim((string) ($raw['background'] ?? '')),
            'badge_style'    => $pick('badge_style', self::BADGES),
            'image_side'     => $pick('image_side', ['right', 'left']),
        ];
    }

    /**
     * The two store links: the block's own when it has one, otherwise the
     * site's from Settings → Native App.
     *
     * A block's link must be a web address — it is typed per page, by the AI
     * too, and a store link is never anything else. The site's links keep
     * whatever form they were saved in, less a script scheme.
     *
     * @return array{ios: string, android: string}
     */
    public static function links(array $content): array
    {
        $links = [];
        foreach (['ios' => 'app_ios_url', 'android' => 'app_android_url'] as $store => $config) {
            $own = trim((string) ($content[$store . '_url'] ?? ''));
            if ($own !== '' && preg_match('#^https?://[^\s"<>]+$#i', $own)) {
                $links[$store] = $own;
                continue;
            }
            $site = trim((string) vela_config($config));
            $links[$store] = preg_match('/^\s*(javascript|data|vbscript):/i', $site) ? '' : $site;
        }

        return $links;
    }

    /**
     * The block's classes and inline style. The background is checked by
     * DesignTokens and paired with an ink that reads on it.
     *
     * @return array{classes: string[], style: string}
     */
    public static function look(array $s, bool $hasImage): array
    {
        $classes = [
            'block-app-download', 'block-app-download--' . $s['layout'], 'block-app-download--' . $s['size'],
            'block-app-download--align-' . $s['text_alignment'], 'block-app-download--badge-' . $s['badge_style'],
        ];
        if ($hasImage) {
            $classes[] = 'has-app-image';
            $classes[] = 'block-app-download--image-' . $s['image_side'];
        }
        $style = 'text-align:' . $s['text_alignment'] . ';';

        if ($bg = DesignTokens::colour($s['background'])) {
            $classes[] = 'has-app-bg';
            $style .= '--app-bg:' . $bg . ';';
            if ($ink = DesignTokens::inkFor($s['background'])) {
                $style .= '--app-ink:' . $ink . ';';
            }
        }

        return ['classes' => $classes, 'style' => $style];
    }
}
