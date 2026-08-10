<?php
namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

abstract class BaseTool
{
    abstract public function execute(array $parameters, ?AiActionLog $actionLog = null): array;

    public function undo(AiActionLog $actionLog): void
    {
        throw new \RuntimeException('Undo not supported for this tool.');
    }

    /**
     * Reject block content carrying keys the block's view never reads.
     *
     * A block's registered defaults.content enumerates every key its Blade view
     * looks up, so an unknown key means an invented shape whose value is dropped
     * at render time — the block then renders empty while the tool reports
     * success. Returns an error array to hand back to the AI, or null if valid.
     */
    protected function validateBlockContent(string $type, $content): ?array
    {
        return $this->validateBlockShape($type, $content, 'content');
    }

    /**
     * Same check for a block's `settings` payload.
     */
    protected function validateBlockSettings(string $type, $settings): ?array
    {
        return $this->validateBlockShape($type, $settings, 'settings');
    }

    /**
     * Strip stray escaping from link fields.
     *
     * Models sometimes emit "\/contact-us" for "/contact-us"; the extra
     * backslash survives into the href and the link silently 404s. Only URL-ish
     * keys are touched, so code/html payloads that legitimately contain
     * backslashes are left alone.
     */
    protected function normalizeBlockUrls($content)
    {
        if (!is_array($content)) {
            return $content;
        }

        foreach ($content as $key => $value) {
            if (is_array($value)) {
                $content[$key] = $this->normalizeBlockUrls($value);
                continue;
            }
            if (is_string($value) && preg_match('/(^|_)(url|link|href|image|src)$/i', (string) $key)) {
                $content[$key] = str_replace('\\/', '/', $value);
            }
        }

        return $content;
    }

    /**
     * Reject markup in text fields.
     *
     * Block views escape their text, so a tag written into a title is shown to
     * visitors verbatim — "<span style='color: yellow'>Welcome</span>" appears
     * on the page as those characters. Styling belongs in text_color or CSS.
     * The html and code blocks are exempt: markup is their content.
     */
    private function validateNoMarkup(string $type, array $content): ?array
    {
        if (in_array($type, ['html', 'code'], true)) {
            return null;
        }

        $offender = null;
        array_walk_recursive($content, function ($value, $key) use (&$offender) {
            if ($offender === null && is_string($value) && preg_match('/<[a-z][a-z0-9]*(\s[^>]*)?\/?>/i', $value)) {
                $offender = [$key, $value];
            }
        });

        if ($offender === null) {
            return null;
        }

        [$key, $value] = $offender;

        return [
            'error' => "'{$key}' contains HTML, which this block escapes — visitors would see the tags as text on the page. "
                . 'Send plain wording only. To colour or style it, set text_color on the block or add a rule with '
                . 'update_custom_css targeting the block class (for example .block-hero-title).',
            'offending_value' => mb_substr($value, 0, 120),
        ];
    }

    /**
     * Check the entries inside a list-style block (icon_box items, gallery
     * images, pricing tiers, …).
     *
     * The top-level check only sees `items`, so an entry built from invented
     * keys — or an icon name that is not a Font Awesome class — passes it and
     * then renders as an empty box.
     */
    private function validateListEntries(string $type, array $content, ?array $definition): ?array
    {
        $example = $definition['content_example'] ?? null;
        if (!is_array($example)) {
            return null;
        }

        foreach ($example as $listKey => $exampleEntries) {
            $entries = $content[$listKey] ?? null;
            if (!is_array($entries) || $entries === [] || !is_array($exampleEntries[0] ?? null)) {
                continue;
            }

            $allowed = array_keys($exampleEntries[0]);

            foreach ($entries as $index => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $unknown = array_values(array_diff(array_keys($entry), $allowed));
                if ($unknown !== []) {
                    return [
                        'error' => "Entry {$index} of '{$listKey}' uses key(s) the {$type} view ignores: "
                            . implode(', ', $unknown) . '. Rebuild each entry using only the keys shown in valid_entry_keys.',
                        'valid_entry_keys' => $allowed,
                        'example_entry'    => $exampleEntries[0],
                    ];
                }

                // An icon is a Font Awesome 6 class, not a description of the
                // thing. "fast-delivery" renders as blank space.
                if (isset($entry['icon']) && is_string($entry['icon'])
                    && !preg_match('/(^|\s)fa-[a-z0-9-]+/i', $entry['icon'])) {
                    return [
                        'error' => "Entry {$index} of '{$listKey}' has icon '{$entry['icon']}', which is not a Font Awesome class, so nothing is drawn. "
                            . 'Use a real Font Awesome 6 free class such as "fas fa-truck", "fas fa-headset", "fas fa-shield-halved". '
                            . 'Put the wording the visitor reads in `title` and `description`, not in `icon`.',
                        'example_entry' => $exampleEntries[0],
                    ];
                }
            }
        }

        return null;
    }

    private function validateBlockShape(string $type, $content, string $section): ?array
    {
        if (!is_array($content) || $content === []) {
            return null;
        }

        $definition = app(\VelaBuild\Core\Vela::class)->blocks()->all()[$type] ?? null;
        $known = $definition['defaults'][$section] ?? null;
        // No enumerated shape (e.g. posts_grid, which is driven by settings
        // alone) — there is nothing to validate against.
        if (!is_array($known) || $known === []) {
            return null;
        }

        $unknown = array_values(array_diff(array_keys($content), array_keys($known)));
        if ($unknown === []) {
            if ($section !== 'content') {
                return null;
            }

            return $this->validateNoMarkup($type, $content)
                ?? $this->validateListEntries($type, $content, $definition);
        }

        return [
            'error' => "Block type '{$type}' has no {$section} key(s): " . implode(', ', $unknown)
                . ". Values under unsupported keys are dropped when the page renders, so the {$section} has no effect. "
                . "Resend using only the keys in valid_{$section}_keys. "
                . 'A block background image is not a settings key — pass background_image (a URL) as its own parameter.',
            "valid_{$section}_keys" => array_keys($known),
            'unknown_keys'          => $unknown,
        ];
    }
}
