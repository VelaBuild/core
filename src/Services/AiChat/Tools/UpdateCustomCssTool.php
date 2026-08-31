<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Models\Page;

class UpdateCustomCssTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $scope = $parameters['scope'] ?? 'site';
        $css = $parameters['css'] ?? '';

        if ($css === '') {
            return ['error' => 'CSS content is required'];
        }

        // Not behind force: a picture that cannot load is a mistake in any
        // stylesheet, not a judgement call about which selectors are real.
        if ($error = $this->validateCssImageUrls($css)) {
            return $error;
        }

        if (!($parameters['force'] ?? false) && $dead = $this->unknownBlockClasses($css)) {
            $suggestions = [];
            foreach ($dead as $class => $suggestion) {
                $suggestions[] = $suggestion ? ".{$class} → .{$suggestion}" : ".{$class}";
            }

            return [
                'error' => 'None of the classes in this CSS exist in the markup, so the rule would do nothing: '
                    . implode(', ', $suggestions) . '. Block classes are spelled .block-<type>-<part> '
                    . '(.block-hero, .block-hero-title, .block-cta-heading). Check the real class names with '
                    . 'get_page_blocks or by reading the block view, then resend. Pass force:true only if you are '
                    . 'styling markup that is not part of a page-builder block.',
                'unknown_classes' => array_keys($dead),
            ];
        }

        if (!($parameters['force'] ?? false) && $blocked = $this->colourOnBareElement($css)) {
            return [
                'error' => "This CSS sets a colour on '{$blocked}', a bare element selector. Page-builder blocks hold their "
                    . 'text colour through class rules (.block-hero-title, .block-cta-heading, …), which outrank element '
                    . 'selectors, so this rule would change nothing inside a block while still hitting every other '
                    . "'{$blocked}' on the site. Target the block class instead — get_page_blocks tells you which blocks "
                    . 'are on the page, and their classes follow the pattern .block-<type>-<part>. To recolour a whole '
                    . 'block, set text_color on it rather than writing CSS. Pass force:true only for deliberate '
                    . 'site-wide typography that is not meant to reach block text.',
                'blocked_selector' => $blocked,
            ];
        }

        if (!($parameters['force'] ?? false) && $undefined = $this->undefinedVariables($css)) {
            return [
                'error' => 'This CSS reads custom properties this site never defines: ' . implode(', ', $undefined)
                    . '. A var() that resolves to nothing falls back to whatever comes after the comma, so the rule '
                    . 'quietly does the opposite of what it looks like — copying font-family: var(--font-inter), '
                    . 'system-ui from another site replaces the real typeface with a generic one. Write the value '
                    . 'itself, or define the property in this same stylesheet first. get_custom_css lists the '
                    . 'properties this site does define.',
                'undefined_variables' => $undefined,
            ];
        }

        if ($scope === 'site') {
            $current = VelaConfig::where('key', 'custom_css_global')->first();
            $previousState = ['scope' => 'site', 'value' => $current?->value];

            if ($actionLog) {
                $actionLog->update(['previous_state' => $previousState]);
            }

            VelaConfig::updateOrCreate(['key' => 'custom_css_global'], ['value' => $css]);
            // Rewrites the cached config and drops every pre-rendered page,
            // both of which sitewide CSS depends on.
            $this->refreshSiteConfigCache();

            return ['success' => true, 'scope' => 'site', 'message' => 'Sitewide CSS updated and cache cleared'];
        }

        if ($scope === 'page') {
            $pageId = $parameters['page_id'] ?? null;
            $pageSlug = $parameters['page_slug'] ?? null;

            $page = $pageId
                ? Page::find($pageId)
                : ($pageSlug ? Page::where('slug', $pageSlug)->first() : null);

            if (!$page) {
                return ['error' => 'Page not found. Provide page_id or page_slug.'];
            }

            $previousState = ['scope' => 'page', 'page_id' => $page->id, 'value' => $page->custom_css];

            if ($actionLog) {
                $actionLog->update(['previous_state' => $previousState]);
            }

            // Sections written into the page — copied from another site, or
            // built from a design — keep their stylesheets here, fenced. This
            // tool replaces a page's CSS wholesale, which is what it is for,
            // but taking those with it leaves every section on the page
            // unstyled while reporting success.
            $preserved = app(\VelaBuild\Core\Services\AiChat\PageCssMerger::class)
                ->preserveFencedRegions((string) $page->custom_css, $css);

            $page->update(['custom_css' => $preserved['css']]);

            // Drop this page's pre-rendered copy so the next visitor is
            // served one with the new stylesheet. Queueing the rebuild instead
            // left it sitting on a queue no worker drains.
            app(\VelaBuild\Core\Services\StaticSiteGenerator::class)->removeHtml('page', $page->slug);

            $result = ['success' => true, 'scope' => 'page', 'page_id' => $page->id, 'message' => "CSS updated for page '{$page->title}' and cache cleared"];

            if ($preserved['kept'] > 0) {
                $result['sections_kept'] = $preserved['kept'];
                $result['note'] = $preserved['kept'] . ' section stylesheet(s) already on this page were kept — '
                    . 'this tool would otherwise have replaced them and left those sections unstyled. To change how '
                    . 'one of those sections looks, write it again with add_designed_section and its replace_row_id.';
            }

            return $result;
        }

        return ['error' => "Invalid scope '{$scope}'. Use 'site' or 'page'."];
    }

    /**
     * Detect CSS whose class selectors match nothing in the block markup.
     *
     * A shortened guess like `.hero h1` (the real class is .block-hero) is
     * accepted by every CSS parser and silently styles nothing, so the change
     * gets reported as done while the page is untouched. Only fires when NONE
     * of the classes are recognised — mixing in a theme or custom row class is
     * normal and must keep working.
     *
     * @return array<string, string|null> unknown class => suggested replacement
     */
    private function unknownBlockClasses(string $css): array
    {
        $known = [];

        // Every class the shipped stylesheets style, plus every class the block
        // views render — between them, anything a page can actually contain.
        $sources = array_merge(
            glob(__DIR__ . '/../../../../public/css/*.css') ?: [],
            glob(__DIR__ . '/../../../../public/css/*/*.css') ?: [],
            glob(__DIR__ . '/../../../../resources/views/public/pages/blocks/*.blade.php') ?: [],
            // Templates carry their own classes in the layout's inline <style>
            // and markup, not in a stylesheet of their own.
            glob(__DIR__ . '/../../../../resources/views/templates/*/*.blade.php') ?: [],
            glob(__DIR__ . '/../../../../resources/views/templates/_partials/*.blade.php') ?: [],
            glob(base_path('resources/views/templates/*/*.blade.php')) ?: []
        );

        foreach ($sources as $source) {
            $body = file_get_contents($source);
            preg_match_all('/\.([a-zA-Z][a-zA-Z0-9_-]*)/', $body, $fromCss);
            preg_match_all('/class="([a-z0-9 _-]+)"/i', $body, $fromMarkup);

            foreach ($fromCss[1] as $class) {
                $known[$class] = true;
            }
            foreach ($fromMarkup[1] as $attribute) {
                foreach (preg_split('/\s+/', trim($attribute)) as $class) {
                    if ($class !== '') {
                        $known[$class] = true;
                    }
                }
            }
        }

        if ($known === []) {
            return [];
        }

        preg_match_all('/\.([a-zA-Z][a-zA-Z0-9_-]*)/', preg_replace('!/\*.*?\*/!s', '', $css), $used);
        $used = array_unique($used[1]);
        if ($used === []) {
            return [];
        }

        $unknown = [];
        foreach ($used as $class) {
            if (isset($known[$class])) {
                return [];
            }
            $unknown[$class] = isset($known['block-' . $class]) ? 'block-' . $class : null;
        }

        // Nothing in the rule matches any class this site can render.
        return $unknown;
    }

    /**
     * Find a rule that colours a bare element selector.
     *
     * Block text is owned by class rules, so `h1 { color: … }` silently loses
     * to them — the change looks applied but nothing moves. Only `color` is
     * checked: font and spacing on element selectors are still normal
     * site-wide typography and cascade as expected.
     */
    /**
     * Custom properties the stylesheet reads but nothing on this site sets.
     *
     * Copying a look from another site brings its variable names along —
     * var(--font-inter) means something on the site it was lifted from and
     * nothing here, so the declaration silently resolves to its fallback and
     * the site ends up worse than before the rule was written.
     */
    private function undefinedVariables(string $css): array
    {
        preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $css, $used);
        if (empty($used[1])) {
            return [];
        }

        // Defined right here, or already known to the site: the block palette
        // and the theme options both land in the rendered stylesheet.
        preg_match_all('/(--[a-z0-9-]+)\s*:/i', $css, $declared);
        $known = array_map('strtolower', $declared[1]);

        foreach (array_keys(GetCustomCssTool::blockVariables()) as $name) {
            $known[] = strtolower($name);
        }
        foreach (['--vela-primary', '--vela-secondary', '--vela-background', '--vela-text-color'] as $name) {
            $known[] = $name;
        }

        $missing = [];
        foreach (array_unique($used[1]) as $name) {
            if (!in_array(strtolower($name), $known, true)) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    private function colourOnBareElement(string $css): ?string
    {
        $elements = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'li', 'span'];

        // Comments can hold anything; drop them before matching.
        $css = preg_replace('!/\*.*?\*/!s', '', $css);

        foreach (self::rules($css) as [$selectors, $body]) {
            if (!preg_match('/(^|[;{\s])color\s*:/i', $body)) {
                continue;
            }

            foreach (explode(',', $selectors) as $selector) {
                $selector = trim($selector);
                if (in_array(strtolower($selector), $elements, true)) {
                    return $selector;
                }
            }
        }

        return null;
    }

    /** @return array<int, array{0: string, 1: string}> */
    private static function rules(string $css): array
    {
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

        return array_map(fn ($m) => [trim($m[1]), $m[2]], $matches);
    }

    public function undo(AiActionLog $actionLog): void
    {
        $state = $actionLog->previous_state;
        if (!$state) {
            throw new \RuntimeException('No previous state to restore.');
        }

        if ($state['scope'] === 'site') {
            if ($state['value'] === null) {
                VelaConfig::where('key', 'custom_css_global')->delete();
            } else {
                VelaConfig::updateOrCreate(['key' => 'custom_css_global'], ['value' => $state['value']]);
            }
        } elseif ($state['scope'] === 'page') {
            $page = Page::find($state['page_id']);
            if ($page) {
                $page->update(['custom_css' => $state['value']]);
            }
        }

        $this->refreshSiteConfigCache();
    }
}
