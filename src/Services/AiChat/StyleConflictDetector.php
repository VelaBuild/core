<?php

namespace VelaBuild\Core\Services\AiChat;

/**
 * Finds custom CSS that hides what a row or block was configured to show.
 *
 * A page's background image lives on the `#row-<id>` / `#block-<id>` wrapper,
 * but a rule like `#block-1 .block-hero { background-color: #0f172a; }` paints
 * an opaque child on top of it, so the image is set, saved and invisible. The
 * chat tools return this alongside the blocks: asked why a hero image will not
 * show, the model otherwise reads the block record, sees the image is there,
 * and reports that everything is fine.
 */
class StyleConflictDetector
{
    /**
     * @param  array<string, string>  $imageTargets  selector (`#block-1`) => the image URL it carries
     * @param  array<string, string>  $sheets        label ("page custom CSS") => css source
     * @return array<int, array<string, string>>
     */
    public static function detect(array $imageTargets, array $sheets): array
    {
        if (!$imageTargets) {
            return [];
        }

        $conflicts = [];

        foreach ($sheets as $origin => $css) {
            if (!is_string($css) || trim($css) === '') {
                continue;
            }

            foreach (self::rules($css) as [$selectorList, $declarations]) {
                $colour = self::opaqueBackground($declarations);
                if ($colour === null) {
                    continue;
                }

                foreach (explode(',', $selectorList) as $selector) {
                    $selector = trim(preg_replace('/\s+/', ' ', $selector));
                    if ($selector === '') {
                        continue;
                    }

                    foreach ($imageTargets as $target => $imageUrl) {
                        // Only a descendant covers the wrapper. The same rule on
                        // the wrapper itself paints *under* its own image, which
                        // is how a fallback colour is supposed to work.
                        if (!self::coversTarget($selector, $target)) {
                            continue;
                        }

                        $conflicts[] = [
                            'origin' => (string) $origin,
                            'selector' => $selector,
                            'declaration' => $colour,
                            'hides' => $target,
                            'image_url' => $imageUrl,
                            'problem' => "`{$selector}` paints an opaque background over `{$target}`, which carries the "
                                . 'background image, so the image never shows.',
                            'fix' => "Remove `{$colour}` from `{$selector}` (or make it transparent / a translucent "
                                . "rgba() overlay). Keep any colour that only sits on `{$target}` itself — that one "
                                . 'renders beneath the image as a fallback.',
                        ];
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    private static function rules(string $css): array
    {
        // At-rule bodies (@media, @supports) nest their own rules; dropping the
        // wrapper leaves the inner rules to be matched on their own.
        $css = preg_replace('/@[a-z-]+[^{]*\{/i', '', $css) ?? $css;
        $css = preg_replace('!/\*.*?\*/!s', '', $css) ?? $css;

        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

        $rules = [];
        foreach ($matches as $match) {
            $rules[] = [trim($match[1]), $match[2]];
        }

        return $rules;
    }

    /**
     * The declaration that paints an opaque colour, or null when the rule
     * leaves whatever is behind it visible.
     */
    private static function opaqueBackground(string $declarations): ?string
    {
        preg_match_all('/(background(?:-color)?)\s*:\s*([^;]+)/i', $declarations, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $property = strtolower(trim($match[1]));
            $value = trim($match[2]);
            $lower = strtolower($value);

            // A `background` shorthand carrying its own image is a replacement,
            // not a cover-up — the author meant that element to show an image.
            if ($property === 'background' && (str_contains($lower, 'url(') || str_contains($lower, 'gradient('))) {
                continue;
            }

            if ($lower === '' || str_contains($lower, 'transparent') || str_contains($lower, 'none')) {
                continue;
            }

            if (preg_match('/rgba?\(\s*[\d.]+[\s,]+[\d.]+[\s,]+[\d.]+[\s,\/]+([\d.]+)\s*\)/i', $value, $alpha)) {
                if ((float) $alpha[1] < 1) {
                    continue;
                }
            }

            if (preg_match('/#[0-9a-f]{8}\b/i', $value) && !preg_match('/#[0-9a-f]{6}ff\b/i', $value)) {
                continue;
            }

            return $property . ': ' . $value;
        }

        return null;
    }

    /**
     * True when the selector matches something *inside* the target, which is
     * what ends up on top of the target's own background.
     */
    private static function coversTarget(string $selector, string $target): bool
    {
        if (!str_contains($selector, $target)) {
            return false;
        }

        // `#block-1` also appears in `#block-12`; require an id boundary.
        if (preg_match('/' . preg_quote($target, '/') . '[0-9]/', $selector)) {
            return false;
        }

        $tail = substr($selector, strpos($selector, $target) + strlen($target));

        // Anything after the id that starts a new compound selector — a
        // descendant, child or sibling — renders over the wrapper.
        return (bool) preg_match('/^\s*[>+~]?\s*[.#a-z\[*]/i', $tail);
    }
}
