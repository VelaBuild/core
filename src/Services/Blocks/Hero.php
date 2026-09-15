<?php

namespace VelaBuild\Core\Services\Blocks;

use VelaBuild\Core\Services\DesignTokens;

/**
 * A hero block's settings, made safe to put into a style attribute.
 *
 * Height and overlay used to be typed as CSS and written into the page as
 * typed, so a value like `80vh;background:url(...)` became a second rule.
 * Everything here is either one of a known set of choices or checked against
 * the grammar of what it claims to be.
 */
class Hero
{
    public const HEIGHTS = ['auto', '50vh', '80vh', '100vh'];
    public const OVERLAYS = ['none', 'light', 'medium', 'dark', 'gradient_bottom', 'gradient_left'];

    /** Widths the background picture is offered at, capped to the file's own. */
    public const WIDTHS = [640, 960, 1280, 1920, 2560];

    private const DEFAULT_OVERLAY = 'rgba(0,0,0,0.4)';

    public static function settings(array $raw): array
    {
        $pick = fn (string $key, array $allowed, $default = null) => in_array($raw[$key] ?? null, $allowed, true) ? $raw[$key] : ($default ?? $allowed[0]);
        $percent = fn (string $key) => max(0, min(100, is_numeric($raw[$key] ?? null) ? (int) round((float) $raw[$key]) : 50));

        return [
            'min_height'              => self::height($raw['min_height'] ?? null),
            'text_alignment'          => $pick('text_alignment', ['center', 'left', 'right']),
            'vertical_align'          => $pick('vertical_align', ['center', 'top', 'bottom']),
            // Absent on every hero saved before there was a choice; those keep
            // the colour they were given in background_overlay.
            'overlay_style'           => in_array($raw['overlay_style'] ?? null, self::OVERLAYS, true) ? $raw['overlay_style'] : null,
            'overlay_color'           => trim((string) ($raw['overlay_color'] ?? '')),
            'background_overlay'      => self::legacyOverlay($raw['background_overlay'] ?? null),
            'text_color_mode'         => $pick('text_color_mode', ['auto', 'light', 'dark']),
            'focal_x'                 => $percent('focal_x'),
            'focal_y'                 => $percent('focal_y'),
            'mobile_background_image' => trim((string) ($raw['mobile_background_image'] ?? '')),
            'heading_level'           => $pick('heading_level', ['auto', 'h1', 'h2']),
            'button_style'            => $pick('button_style', ['solid', 'pill', 'outline']),
            'button_color'            => trim((string) ($raw['button_color'] ?? '')),
        ];
    }

    /** A height from the tiles, or a single length; anything else is the default. */
    public static function height(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (in_array($value, self::HEIGHTS, true)) {
            return $value;
        }

        return preg_match('/^[0-9]{1,4}(\.[0-9]+)?(px|vh|svh|dvh|lvh|rem|em)$/', $value) ? $value : '80vh';
    }

    /**
     * The overlay colour a hero saved before overlay_style had: a colour, or a
     * gradient an AI build wrote — kept only while it is nothing but a
     * gradient of colours and stops.
     */
    public static function legacyOverlay(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return self::DEFAULT_OVERLAY;
        }
        if ($colour = DesignTokens::colour($value)) {
            return $colour;
        }
        if (preg_match('/^(?:linear|radial)-gradient\([a-z0-9#%.,\s()\/-]+\)$/i', $value) && !preg_match('/url|expression|;|\\\\/i', $value)) {
            return $value;
        }

        return self::DEFAULT_OVERLAY;
    }

    /**
     * The overlay as `background` declarations — a plain rgba first, for a
     * browser that cannot mix colours, then the version in the chosen colour.
     */
    public static function overlayCss(array $s): string
    {
        if ($s['overlay_style'] === null) {
            return 'background:' . $s['background_overlay'] . ';';
        }

        $colour = DesignTokens::colour($s['overlay_color']) ?? '#000000';
        $mix = fn (int $pct) => 'color-mix(in srgb, ' . $colour . ' ' . $pct . '%, transparent)';

        return match ($s['overlay_style']) {
            'none'   => '',
            'light'  => 'background:rgba(0,0,0,0.25);background:' . $mix(25) . ';',
            'medium' => 'background:rgba(0,0,0,0.45);background:' . $mix(45) . ';',
            'dark'   => 'background:rgba(0,0,0,0.65);background:' . $mix(65) . ';',
            'gradient_bottom' => 'background:linear-gradient(to top, rgba(0,0,0,0.8), rgba(0,0,0,0.3) 55%, rgba(0,0,0,0));'
                . 'background:linear-gradient(to top, ' . $mix(80) . ', ' . $mix(30) . ' 55%, transparent);',
            'gradient_left' => 'background:linear-gradient(to right, rgba(0,0,0,0.8), rgba(0,0,0,0.35) 55%, rgba(0,0,0,0));'
                . 'background:linear-gradient(to right, ' . $mix(80) . ', ' . $mix(35) . ' 55%, transparent);',
        };
    }

    /**
     * Light words or the theme's own ink.
     *
     * A hero's words were whatever colour the theme gave them: white in the
     * block stylesheet, near-black in a theme that set --hero-ink — over no
     * picture that was white on near-white, and over a dark shade it was
     * black on black. Automatic now reads what is behind the words. A hero
     * saved before there was a choice says `theme` and keeps that colour.
     */
    public static function ink(array $s, bool $hasImage): string
    {
        if ($s['text_color_mode'] !== 'auto') {
            return $s['text_color_mode'];
        }
        if ($s['overlay_style'] === null) {
            return 'theme';
        }
        if ($hasImage) {
            return 'light';
        }

        // A fade is dark where the words sit, so it counts as a dark ground.
        return in_array($s['overlay_style'], ['medium', 'dark', 'gradient_bottom', 'gradient_left'], true) ? 'light' : 'dark';
    }

    /** Picture widths worth offering: none wider than the file, at least one. */
    public static function widths(string $src): array
    {
        $path = function_exists('vela_image_relative_path') ? vela_image_relative_path($src) : null;
        $intrinsic = $path !== null && function_exists('vela_image_dimensions') ? (vela_image_dimensions($path)[0] ?? 0) : 0;
        if ($intrinsic <= 0) {
            return self::WIDTHS;
        }

        $widths = array_values(array_filter(self::WIDTHS, fn ($w) => $w <= $intrinsic));

        return $widths ?: [min(self::WIDTHS[0], $intrinsic)];
    }

    /**
     * Whether this is the first hero on the page being drawn: that one is the
     * page's heading and its likeliest largest paint.
     */
    public static function claimFirst(): bool
    {
        $request = request();
        $first = !$request->attributes->get('vela_hero_drawn', false);
        $request->attributes->set('vela_hero_drawn', true);

        return $first;
    }
}
