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
     * Rebuild the static site-config cache the public site actually reads.
     *
     * Settings live in vela_configs, but no request reads that table — the
     * provider boots `vela.*` from storage/app/vela-site.php instead. A tool
     * that writes the row and stops leaves the site serving the old value
     * until someone happens to save something in the admin, so switching
     * theme or setting sitewide CSS reported success and changed nothing a
     * visitor could see.
     */
    protected function refreshSiteConfigCache(): void
    {
        try {
            app(\VelaBuild\Core\Services\SiteConfigWriter::class)->write();
            // This process booted with the old values, and whatever it renders
            // from here — including a snapshot an observer rebuilds later in
            // the same request — would otherwise carry the previous theme.
            \VelaBuild\Core\Services\SiteConfigWriter::apply();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Vela: could not rebuild the site config cache: ' . $e->getMessage());
        }

        // Where static serving is on, a visitor never reaches the framework —
        // public/index.php hands back a pre-rendered file. A setting that shows
        // up in the markup of every page has to drop those files too, or the
        // config is right while the page people are served is still the old
        // one. Same move vela:theme-reset makes.
        try {
            app(\VelaBuild\Core\Services\StaticSiteGenerator::class)->purgeHtml();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Vela: could not clear the static pages: ' . $e->getMessage());
        }
    }

    /**
     * Render the block on its own and report what a visitor would read.
     *
     * The prompt has told the model to check its work since the beginning and
     * it never has — not once in fifty conversations. So the tool does the
     * looking and hands the answer back with the result: a block that renders
     * to nothing comes back as a warning instead of a bare success, and there
     * is no longer a version of "done" that the model can report without the
     * evidence in front of it.
     */
    protected function describeRenderedBlock(\VelaBuild\Core\Models\PageBlock $block): array
    {
        $definition = app(\VelaBuild\Core\Vela::class)->blocks()->all()[$block->type] ?? null;
        $view = view()->exists('vela::public.pages.blocks.' . $block->type)
            ? 'vela::public.pages.blocks.' . $block->type
            : ($definition['view'] ?? null);

        if (!$view || !view()->exists($view)) {
            return [];
        }

        try {
            $html = view($view, [
                'block' => $block,
                'page'  => $block->row?->page,
            ])->render();
        } catch (\Throwable $e) {
            return ['warning' => "This block throws when the page renders, so the page is now broken for visitors: "
                . $e->getMessage() . '. Undo it or fix the content before telling the user anything was added.'];
        }

        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8')));

        // A block with its own empty state says something like "No testimonials
        // yet" — visible words, so the blank check below never fires, while the
        // content the tool just stored reached nothing. Render the same block
        // with its content taken away: if a visitor reads the same either way,
        // the payload made no difference.
        $emptied = $block->replicate();
        $emptied->content = $definition['defaults']['content'] ?? [];
        try {
            $placeholder = trim(preg_replace('/\s+/u', ' ', html_entity_decode(
                strip_tags(view($view, ['block' => $emptied, 'page' => $block->row?->page])->render()),
                ENT_QUOTES,
                'UTF-8'
            )));
        } catch (\Throwable $e) {
            $placeholder = null;
        }

        if ($placeholder !== null && $placeholder !== '' && $placeholder === $text) {
            return ['warning' => 'This block renders exactly as it would with no content at all — a visitor reads "'
                . \Illuminate\Support\Str::limit($text, 80) . '". What was stored is under a key the view does not read, '
                . 'so none of it reached the page. Check the shape with list_block_types and send it again; '
                . 'do not report this as added.'];
        }

        // Some blocks are pictures or embeds and carry no words at all.
        if ($text === '' && !preg_match('/<(img|iframe|svg|input|video)\b/i', $html)) {
            return ['warning' => 'This block renders completely empty — a visitor sees a blank gap where it sits. '
                . 'The content was stored but the view read none of it. Check the shape with list_block_types and '
                . 'send it again; do not report this as added.'];
        }

        return ['visitor_sees' => \Illuminate\Support\Str::limit($text, 200)];
    }

    /**
     * Refuse a background and text colour nobody could read.
     *
     * Changing one of the pair without looking at the other is how a row ends
     * up white on white: asked to lighten a section, the model sets the
     * background and leaves the white text that was chosen for the dark one.
     * A background image can carry its own contrast, so a section that has one
     * is left alone.
     */
    protected function validateColourContrast(?string $background, ?string $text, ?string $backgroundImage = null): ?array
    {
        if ($backgroundImage || !$this->isHexColour($background) || !$this->isHexColour($text)) {
            return null;
        }

        $ratio = $this->contrastRatio($background, $text);
        if ($ratio >= 3.0) {
            return null;
        }

        return [
            'error' => "Text at {$text} on a {$background} background comes out at "
                . number_format($ratio, 2) . ':1, which visitors cannot read '
                . '(3:1 is the floor for headline-sized text). Both halves of the pair have to be set together: '
                . 'send text_color along with background_color, or pick a background that keeps the text it already has readable.',
            'background_color' => $background,
            'text_color'       => $text,
            'contrast_ratio'   => round($ratio, 2),
        ];
    }

    private function isHexColour(?string $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', trim($value)) === 1;
    }

    /** WCAG relative-luminance contrast, 1:1 (identical) to 21:1 (black on white). */
    private function contrastRatio(string $a, string $b): float
    {
        $luminance = function (string $hex): float {
            $hex = ltrim(trim($hex), '#');
            $channels = [];
            foreach ([0, 2, 4] as $offset) {
                $value = hexdec(substr($hex, $offset, 2)) / 255;
                $channels[] = $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            }

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };

        $first = $luminance($a);
        $second = $luminance($b);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
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

    /**
     * Check the keys inside a settings map whose default spells out its own
     * entries — a contact form's `fields`, for example.
     *
     * The top-level check only sees `fields`, so an invented entry passes it
     * and the block then behaves worse than if the setting had been dropped:
     * the controller builds a validation rule from every enabled entry while
     * the view can only draw the ones it knows, leaving a required field the
     * visitor has no way to fill and a form nobody can submit.
     */
    private function validateSettingsMaps(string $type, array $settings, array $known): ?array
    {
        foreach ($known as $key => $default) {
            if (!is_array($default) || $default === [] || array_keys($default) === range(0, count($default) - 1)) {
                continue;
            }

            $given = $settings[$key] ?? null;
            if (!is_array($given)) {
                continue;
            }

            $unknown = array_values(array_diff(array_keys($given), array_keys($default)));
            if ($unknown !== []) {
                return [
                    'error' => "'{$key}' on a {$type} block has no entry named " . implode(', ', $unknown)
                        . '. The block can only draw the entries it ships with, so an added one is never shown to visitors — '
                        . 'and if it is marked required, the form rejects every submission for a field nobody can see. '
                        . "Resend '{$key}' using only the entries in valid_entries; each may be turned off or made optional.",
                    'valid_entries' => array_keys($default),
                    'unknown_entries' => $unknown,
                ];
            }
        }

        return null;
    }

    /**
     * Reject an article whose pictures point at files that are not there.
     *
     * Models reach for a filename that describes the picture — "diver-checking
     * -gear.png" — instead of the address generate_image handed back, and the
     * article then renders a blank space while the tool reports success.
     * Only this site's own files are checked; an external host is not ours to
     * vouch for.
     */
    protected function validateArticleImages(string $editorJson): ?array
    {
        $document = json_decode($editorJson, true);
        if (!is_array($document)) {
            return null;
        }

        foreach ($document['blocks'] ?? [] as $block) {
            $urls = [];
            if (($block['type'] ?? '') === 'image') {
                $urls[] = (string) ($block['data']['file']['url'] ?? '');
            }
            if (preg_match_all('/<img[^>]+src="([^"]+)"/i', json_encode($block['data'] ?? []), $inline)) {
                $urls = array_merge($urls, $inline[1]);
            }

            foreach ($urls as $url) {
                $path = $this->localImagePath($url);
                if ($path === null || is_file($path)) {
                    continue;
                }

                return [
                    'error' => "The article points at an image that is not on this site: {$url}. "
                        . 'A picture only shows up when the address is the exact `url` that generate_image returned — '
                        . 'a filename invented to describe the picture leaves an empty gap on the page. '
                        . 'Generate the image (or find an existing one with manage_media) and paste the url it gives back, character for character.',
                    'missing_image' => $url,
                ];
            }
        }

        return null;
    }

    private function localImagePath(string $url): ?string
    {
        $url = str_replace('\\/', '/', trim($url));
        if ($url === '' || str_starts_with($url, 'data:')) {
            return null;
        }

        $path = $url;
        if (preg_match('#^https?://#i', $url)) {
            $host = parse_url($url, PHP_URL_HOST);
            if ($host !== parse_url((string) config('app.url'), PHP_URL_HOST)) {
                return null;
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
        } elseif (!str_starts_with($path, '/')) {
            return null;
        }

        return public_path(ltrim($path, '/'));
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
                return $this->validateSettingsMaps($type, $content, $known);
            }

            return $this->validateNoMarkup($type, $content)
                ?? $this->validateListEntries($type, $content, $definition);
        }

        $error = [
            'error' => "Block type '{$type}' has no {$section} key(s): " . implode(', ', $unknown)
                . ". Values under unsupported keys are dropped when the page renders, so the {$section} has no effect. "
                . "Resend using only the keys in valid_{$section}_keys. "
                . 'A block background image is not a settings key — pass background_image (a URL) as its own parameter.',
            "valid_{$section}_keys" => array_keys($known),
            'unknown_keys'          => $unknown,
        ];

        // Knowing which keys are valid does not explain why the one the model
        // reached for is missing. Where the block spells that out, pass it on —
        // otherwise the same wrong key gets retried in a different shape.
        if (!empty($definition['shape_note'])) {
            $error['note'] = $definition['shape_note'];
        }

        return $error;
    }
}
