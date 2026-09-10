<?php

namespace VelaBuild\Core\Services;

/**
 * What a row or a block means when it says it is a colour.
 *
 * A page used to store the answer as a literal: `#ffffff`, chosen from a colour
 * picker. That reads correctly on the day it is chosen and is wrong forever
 * after, because it records a value and not an intention. Switch a site from a
 * light theme to a dark one and forty pages of rows still say #ffffff — white
 * text on a white ground on the pages the owner had styled by hand, and no way
 * to fix it but to open every one of them.
 *
 * A colour may now instead be a name from the theme's palette, stored as
 * `token:accent`, which resolves to the `--vela-*` custom property behind it
 * (see docs/design-tokens.md). Re-skinning a site re-colours those rows.
 *
 * Literals still work, and are still offered — sometimes a section really is
 * meant to be that exact green, and taking the choice away would be worse than
 * the problem. They are validated on the way out, which they were not before:
 * these values are written into a `style` attribute, and `text_alignment` in
 * particular went in unchecked.
 */
class DesignTokens
{
    /** The prefix marking a stored value as a palette name rather than a literal. */
    public const PREFIX = 'token:';

    /**
     * The palette, in the order it is offered.
     *
     * Each entry is [custom property, fallback, label, role]. The fallback is
     * what the browser paints on a theme that has not declared the token, and
     * doubles as the swatch the editor draws. `role` says which of the two
     * pickers offers it: a ground, a text colour, or both.
     */
    public const PALETTE = [
        'accent'     => ['--vela-primary',       '#2563eb', 'Accent',           'both'],
        'accent-ink' => ['--vela-primary-ink',   '#ffffff', 'On accent',        'text'],
        'ink'        => ['--vela-ink',           '#1f2937', 'Body text',        'both'],
        'ink-soft'   => ['--vela-ink-soft',      '#374151', 'Secondary text',   'text'],
        'muted'      => ['--vela-muted',         '#6b7280', 'Muted text',       'text'],
        'background' => ['--vela-background',    '#ffffff', 'Page background',  'bg'],
        'surface'    => ['--vela-surface',       '#f9fafb', 'Surface',          'bg'],
        'card'       => ['--vela-card-bg',       '#ffffff', 'Card',             'bg'],
        'line'       => ['--vela-line',          '#e5e7eb', 'Rule',             'bg'],
    ];

    /**
     * A stored colour as CSS, or null if it is empty or not a colour at all.
     */
    public static function colour(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, self::PREFIX)) {
            $name = substr($value, strlen(self::PREFIX));

            if (!isset(self::PALETTE[$name])) {
                return null;
            }

            [$property, $fallback] = self::PALETTE[$name];

            return "var({$property}, {$fallback})";
        }

        return self::literalColour($value);
    }

    /**
     * A literal colour, or null.
     *
     * The same grammar the theme colour settings use: a hex, one of the CSS
     * colour functions carrying nothing but numbers, or a bare keyword. It
     * exists because these values reach a `style` attribute, where a value of
     * `red; position: fixed; inset: 0; z-index: 9999` is a page-covering
     * overlay that no editor screen would show you.
     */
    private static function literalColour(string $value): ?string
    {
        $ok = preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value)
            || preg_match('/^(?:rgb|rgba|hsl|hsla)\(\s*[0-9.,%\s\/deg-]+\)$/i', $value)
            || preg_match('/^[a-z]{3,20}$/i', $value);

        return $ok ? $value : null;
    }

    /**
     * A stored spacing value as CSS, or null.
     *
     * One length is vertical space; two or four are a full shorthand. `"0"` is
     * a real answer and must survive, which is why the caller cannot ask this
     * question with a truthiness check.
     */
    public static function spacing(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $length = '(?:0|-?[0-9]*\.?[0-9]+(?:px|rem|em|vh|vw|%))';

        return preg_match("/^{$length}(?:\s+{$length}){0,3}$/i", $value) ? $value : null;
    }

    /** A stored alignment, or null. */
    public static function alignment(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['left', 'center', 'right', 'justify'], true) ? $value : null;
    }

    /**
     * The palette as the editor needs it: name, label, swatch, and which
     * picker offers it.
     */
    public static function paletteForEditor(): array
    {
        $out = [];

        foreach (self::PALETTE as $name => [$property, $fallback, $label, $role]) {
            $out[] = [
                'value'    => self::PREFIX . $name,
                'label'    => $label,
                'swatch'   => $fallback,
                'property' => $property,
                'role'     => $role,
            ];
        }

        return $out;
    }
}
