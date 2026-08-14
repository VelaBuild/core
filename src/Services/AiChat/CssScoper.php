<?php

namespace VelaBuild\Core\Services\AiChat;

/**
 * Take another site's stylesheets and make them safe to drop into one page.
 *
 * Rebuilding a section out of page-builder blocks only ever gets the shape
 * roughly right, because a block's look belongs to this site's template. The
 * way to actually reproduce a section is to keep its own markup and its own
 * CSS — but pasted in whole, that CSS restyles the entire site: `body`,
 * `h2`, `.container` and every other generic selector would reach the header,
 * the footer and every other page.
 *
 * So every rule is rewritten to sit under one wrapper class, and rules whose
 * selectors match nothing in the imported markup are dropped — which is most
 * of a modern stylesheet, and the difference between 400KB of CSS and a few
 * dozen KB that fit in the page's own stylesheet field.
 */
class CssScoper
{
    /**
     * Hard ceiling on the CSS kept for one import. A utility framework needs
     * room — a single section of a Tailwind page keeps ~600 rules once the
     * escaped class names are read properly, and cutting that in half is what
     * a half-styled section looks like.
     */
    private const MAX_OUTPUT = 200_000;

    /** @var array<string, true> classes present in the imported markup */
    private array $classes = [];

    /** @var array<string, true> ids present in the imported markup */
    private array $ids = [];

    /** @var array<string, true> tag names present in the imported markup */
    private array $tags = [];

    private int $kept = 0;

    private int $dropped = 0;

    /**
     * @param string $css       every stylesheet of the source page, concatenated
     * @param string $html      the markup being imported
     * @param string $wrapper   wrapper class, without the leading dot
     * @param string $sourceUrl address the CSS came from, for resolving url()
     */
    public function scope(string $css, string $html, string $wrapper, string $sourceUrl = ''): array
    {
        $this->collectUsedSelectors($html);
        $this->kept = 0;
        $this->dropped = 0;

        $css = $this->stripComments($css);
        $css = self::absolutizeUrls($css, $sourceUrl);

        $out = $this->rewriteBlock($css, '.' . $wrapper);
        $truncated = false;
        if (strlen($out) > self::MAX_OUTPUT) {
            // Cut on a rule boundary — half a declaration block would break
            // every rule after it in the page's stylesheet.
            $cut = strrpos(substr($out, 0, self::MAX_OUTPUT), '}');
            $out = $cut === false ? '' : substr($out, 0, $cut + 1);
            $truncated = true;
        }

        return [
            'css'           => trim($out),
            'rules_kept'    => $this->kept,
            'rules_dropped' => $this->dropped,
            'truncated'     => $truncated,
        ];
    }

    /**
     * Walk a block of CSS, rewriting style rules and recursing into the
     * at-rules that contain them.
     */
    private function rewriteBlock(string $css, string $wrapper): string
    {
        $out = '';
        $length = strlen($css);
        $i = 0;

        while ($i < $length) {
            // Find where this rule's prelude ends. Not with strpos: a utility
            // framework escapes arbitrary values into class names, so a
            // selector can legitimately contain `{`, `;`, quotes and brackets
            // — `.\[-webkit-transform\:translate\(0px\)\]`. Taking the first
            // raw `{` cut such a selector in half and every rule after it was
            // read as garbage, which is how a 124KB stylesheet reached the
            // browser as 56 usable rules and the page looked unstyled.
            $end = $this->findPreludeEnd($css, $i);

            // A statement at-rule (@import, @charset, @layer a,b;) ends at the
            // semicolon and carries no block. Dropped: an @import would pull
            // in a whole second stylesheet, unscoped.
            if ($end['type'] === 'statement') {
                $i = $end['pos'] + 1;
                continue;
            }

            if ($end['type'] === 'eof') {
                break;
            }

            $braceOpen = $end['pos'];
            $prelude = trim(substr($css, $i, $braceOpen - $i));
            $braceClose = $this->matchingBrace($css, $braceOpen);

            if ($braceClose === false) {
                // The block never closes — a stylesheet cut short, or one
                // brace lost inside a string. Giving up here threw away
                // everything that followed, and since a utility framework
                // wraps its whole file in one @layer, that was the entire
                // stylesheet. Salvage what is inside it instead.
                $rest = substr($css, $braceOpen + 1);
                if (str_starts_with($prelude, '@')) {
                    return $out . $this->rewriteAtRule($prelude, $rest, $wrapper);
                }
                break;
            }

            $body = substr($css, $braceOpen + 1, $braceClose - $braceOpen - 1);
            $i = $braceClose + 1;

            if ($prelude === '') {
                continue;
            }

            if (str_starts_with($prelude, '@')) {
                $out .= $this->rewriteAtRule($prelude, $body, $wrapper);
                continue;
            }

            $selectors = $this->rewriteSelectorList($prelude, $wrapper);
            if ($selectors === '') {
                $this->dropped++;
                continue;
            }

            $this->kept++;
            $out .= $selectors . '{' . trim($body) . "}\n";
        }

        return $out;
    }

    private function rewriteAtRule(string $prelude, string $body, string $wrapper): string
    {
        $name = strtolower(strtok(substr($prelude, 1), " \t\n(") ?: '');

        // Kept whole: they define resources rather than target elements, so
        // there is nothing to scope and dropping them would lose the section's
        // typeface and its animations.
        if (in_array($name, ['font-face', 'keyframes', '-webkit-keyframes', 'page', 'counter-style', 'property'], true)) {
            $this->kept++;
            return $prelude . '{' . trim($body) . "}\n";
        }

        // Cascade layers are unwrapped, not kept.
        //
        // Every rule in a layer loses to every rule that is in no layer at
        // all, whatever the specificity. The host site's own stylesheet is
        // unlayered, so an imported section kept inside @layer had its
        // padding and margins wiped by a plain `*{margin:0;padding:0}` reset
        // while its fonts and colours came through — the section rendered
        // flush to the edge of the page with its spacing gone. Flattened, the
        // rules sit at the same level as the site's own and win on the
        // specificity the wrapper class gives them. Order within the file is
        // preserved, which is what decides between them.
        if ($name === 'layer') {
            return $this->rewriteBlock($body, $wrapper);
        }

        // Conditional groups hold ordinary rules — scope what is inside them.
        if (in_array($name, ['media', 'supports', 'container'], true)) {
            $inner = $this->rewriteBlock($body, $wrapper);
            if (trim($inner) === '') {
                return '';
            }
            return $prelude . "{\n" . $inner . "}\n";
        }

        return '';
    }

    /**
     * Rewrite one comma-separated selector list, dropping the parts that can
     * match nothing in the imported markup.
     */
    private function rewriteSelectorList(string $prelude, string $wrapper): string
    {
        $kept = [];
        // Not explode(): `:is(.a,.b)` and `:not(.x,.y)` carry commas of their
        // own, and splitting on them produced two halves of an invalid
        // selector — which the browser then threw away whole.
        foreach ($this->splitSelectors($prelude) as $selector) {
            $selector = trim(preg_replace('/\s+/', ' ', $selector) ?? '');
            if ($selector === '') {
                continue;
            }

            $rewritten = $this->rewriteSelector($selector, $wrapper);
            foreach ($rewritten as $one) {
                $kept[$one] = true;
            }
        }

        return implode(',', array_keys($kept));
    }

    /**
     * Split a selector list on its top-level commas only.
     *
     * @return string[]
     */
    private function splitSelectors(string $prelude): array
    {
        $parts = [];
        $current = '';
        $quote = null;
        $depth = 0;
        $length = strlen($prelude);

        for ($i = 0; $i < $length; $i++) {
            $char = $prelude[$i];

            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $prelude[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '\\' && $i + 1 < $length) {
                $current .= $char . $prelude[++$i];
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;
                continue;
            }
            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth = max(0, $depth - 1);
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    /** @return string[] zero, one or two selectors to emit */
    private function rewriteSelector(string $selector, string $wrapper): array
    {
        // The page-level selectors describe the document, and the wrapper is
        // the closest thing the imported section has to one.
        $bare = preg_replace('/^(html|body|:root)\b/i', '', $selector) ?? $selector;
        if ($bare !== $selector) {
            $bare = trim($bare);
            return [$bare === '' ? $wrapper : $wrapper . ' ' . $bare];
        }

        if (!$this->selectorCanMatch($selector)) {
            return [];
        }

        // A single compound selector may describe the section element itself
        // (which becomes the wrapper) as well as something inside it, so emit
        // both forms — `.hero` has to keep working when .hero IS the wrapper.
        // Only for a class or id: gluing the wrapper onto a tag produced
        // `.vela-import-x input`'s evil twin, `.vela-import-xinput`, which
        // matches nothing and is pure weight.
        if (preg_match('/^[.#]/', $selector)
            && preg_match('/^[.#][a-z0-9_\\-]+(?:[.#:\[][^ >+~]*)*$/i', $selector)
            && !preg_match('/[ >+~]/', $selector)) {
            return [$wrapper . ' ' . $selector, $wrapper . $selector];
        }

        return [$wrapper . ' ' . $selector];
    }

    /**
     * Could this selector match anything in the imported markup?
     *
     * Approximate on purpose: every class and id the selector names must be
     * present, but combinators and pseudo-classes are not evaluated. Erring
     * towards keeping a rule costs a few bytes; erring the other way loses
     * styling the section needs.
     */
    private function selectorCanMatch(string $selector): bool
    {
        if ($this->classes === [] && $this->ids === [] && $this->tags === []) {
            return true;
        }

        $names = $this->selectorNames($selector);

        foreach ($names['classes'] as $class) {
            if (!isset($this->classes[$class])) {
                return false;
            }
        }

        foreach ($names['ids'] as $id) {
            if (!isset($this->ids[$id])) {
                return false;
            }
        }

        // A tag-only selector is worth keeping only if the tag is in there.
        if ($names['classes'] === [] && $names['ids'] === [] && $names['tags'] !== []) {
            foreach ($names['tags'] as $tag) {
                if (isset($this->tags[$tag])) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    /**
     * Read the class, id and tag names out of one selector.
     *
     * Hand-walked rather than matched with a pattern because a utility
     * framework writes its class names with escapes — `.py-0\.5`,
     * `.focus-visible\:outline-2`, `.outline-\[var\(--x\)\]` — and a regex
     * that stops at the backslash reads them as `py-0` and `focus-visible`,
     * names no markup carries. Every such rule then looked unused and was
     * dropped: a Tailwind page came through with 58 of its rules and looked
     * like unstyled HTML, which is exactly the "CSS didn't come" symptom.
     *
     * @return array{classes:string[], ids:string[], tags:string[]}
     */
    private function selectorNames(string $selector): array
    {
        $classes = [];
        $ids = [];
        $tags = [];

        $length = strlen($selector);
        $i = 0;
        $atStart = true; // a tag name is only a tag at the start of a compound

        while ($i < $length) {
            $char = $selector[$i];

            if ($char === '\\') {
                $i += 2;
                continue;
            }

            if ($char === '[') {
                // Attribute selector — skip it whole; it names no class.
                $depth = 1;
                $i++;
                while ($i < $length && $depth > 0) {
                    if ($selector[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($selector[$i] === '[') {
                        $depth++;
                    } elseif ($selector[$i] === ']') {
                        $depth--;
                    }
                    $i++;
                }
                $atStart = false;
                continue;
            }

            if ($char === ':') {
                // Pseudo-class or element. Its name is not a class, and its
                // argument list may hold selectors of its own — walking past
                // the parentheses keeps :not(.a) from reading as class `a`,
                // which would drop the rule for a class that need not be here.
                $i++;
                if ($i < $length && $selector[$i] === ':') {
                    $i++;
                }
                while ($i < $length && preg_match('/[a-z-]/i', $selector[$i])) {
                    $i++;
                }
                if ($i < $length && $selector[$i] === '(') {
                    $depth = 1;
                    $i++;
                    while ($i < $length && $depth > 0) {
                        if ($selector[$i] === '\\') {
                            $i += 2;
                            continue;
                        }
                        if ($selector[$i] === '(') {
                            $depth++;
                        } elseif ($selector[$i] === ')') {
                            $depth--;
                        }
                        $i++;
                    }
                }
                $atStart = false;
                continue;
            }

            if ($char === '.' || $char === '#') {
                $i++;
                $name = $this->readName($selector, $i);
                if ($name !== '') {
                    if ($char === '.') {
                        $classes[] = $name;
                    } else {
                        $ids[] = $name;
                    }
                }
                $atStart = false;
                continue;
            }

            if (preg_match('/[a-z*]/i', $char)) {
                $name = $this->readName($selector, $i);
                if ($atStart && $name !== '' && $name !== '*') {
                    $tags[] = strtolower($name);
                }
                $atStart = false;
                continue;
            }

            // Whitespace and combinators end the compound; whatever follows
            // starts a new one, so a tag name there counts again.
            if (preg_match('/[\s>+~,]/', $char)) {
                $atStart = true;
            }
            $i++;
        }

        return ['classes' => $classes, 'ids' => $ids, 'tags' => $tags];
    }

    /** Read one identifier from $i, resolving CSS escapes, advancing $i. */
    private function readName(string $selector, int &$i): string
    {
        $name = '';
        $length = strlen($selector);

        while ($i < $length) {
            $char = $selector[$i];

            if ($char === '\\') {
                // `\.` in a selector is a literal dot in the class name — the
                // markup spells it `py-0.5`.
                if ($i + 1 < $length) {
                    $name .= $selector[$i + 1];
                }
                $i += 2;
                continue;
            }

            if (preg_match('/[a-z0-9_*-]/i', $char) || ord($char) > 127) {
                $name .= $char;
                $i++;
                continue;
            }

            break;
        }

        return $name;
    }

    private function collectUsedSelectors(string $html): void
    {
        $this->classes = [];
        $this->ids = [];
        $this->tags = [];

        // Decoded first. Saved markup escapes the ampersand, so a utility
        // class reads `[&amp;_input]:pl-3` in the HTML while the stylesheet
        // spells the same class `[&_input]:pl-3`. Compared raw, every one of
        // those rules looked unused and was thrown away — which on a page of
        // form controls is most of their styling, and the copied form came
        // out as bare browser inputs.
        if (preg_match_all('/class="([^"]*)"/i', $html, $matches)) {
            foreach ($matches[1] as $value) {
                $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                foreach (preg_split('/\s+/', trim($value)) ?: [] as $class) {
                    if ($class !== '') {
                        $this->classes[$class] = true;
                    }
                }
            }
        }

        if (preg_match_all('/id="([^"]+)"/i', $html, $matches)) {
            foreach ($matches[1] as $id) {
                $this->ids[trim(html_entity_decode($id, ENT_QUOTES | ENT_HTML5, 'UTF-8'))] = true;
            }
        }

        if (preg_match_all('/<([a-z][a-z0-9]*)\b/i', $html, $matches)) {
            foreach ($matches[1] as $tag) {
                $this->tags[strtolower($tag)] = true;
            }
        }
    }

    /**
     * Make url(...) references absolute so fonts and images still resolve.
     *
     * Public because a linked stylesheet's addresses are relative to that
     * file, not to the page — resolving them at fetch time is the only way
     * `url(../img/bg.jpg)` inside /assets/css/app.css lands on the right file.
     */
    public static function absolutizeUrls(string $css, string $sourceUrl): string
    {
        if ($sourceUrl === '') {
            return $css;
        }

        return preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            function ($m) use ($sourceUrl) {
                $url = trim($m[2]);
                if ($url === '' || str_starts_with($url, 'data:') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
                    return $m[0];
                }
                return 'url("' . self::absolutize($url, $sourceUrl) . '")';
            },
            $css
        ) ?? $css;
    }

    public static function absolutize(string $url, string $baseUrl): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:') || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return $url;
        }

        $parts = parse_url($baseUrl);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($url, '//')) {
            return $parts['scheme'] . ':' . $url;
        }
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }
        if (str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
            return $url;
        }

        $path = $parts['path'] ?? '/';
        if (!str_ends_with($path, '/')) {
            $path = rtrim(dirname($path), '/') . '/';
        }

        // Walk the ../ segments off rather than stripping the dots, which
        // would turn ../img/bg.jpg into a sibling of the wrong directory.
        $segments = [];
        foreach (explode('/', $path . $url) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $origin . '/' . implode('/', $segments);
    }

    private function stripComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    }

    /**
     * Where does this rule's prelude end — at its `{`, at a `;`, or nowhere?
     *
     * @return array{type:'block'|'statement'|'eof', pos:int}
     */
    private function findPreludeEnd(string $css, int $from): array
    {
        $length = strlen($css);
        $quote = null;
        $parens = 0;

        for ($i = $from; $i < $length; $i++) {
            $char = $css[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '\\') {
                $i++;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '(') {
                $parens++;
                continue;
            }
            if ($char === ')') {
                $parens = max(0, $parens - 1);
                continue;
            }
            // Inside url(...) or a media condition, `{` and `;` are data.
            if ($parens > 0) {
                continue;
            }
            if ($char === '{') {
                return ['type' => 'block', 'pos' => $i];
            }
            if ($char === ';') {
                return ['type' => 'statement', 'pos' => $i];
            }
        }

        return ['type' => 'eof', 'pos' => $length];
    }

    /**
     * @return int|false position of the brace closing the one at $open
     */
    private function matchingBrace(string $css, int $open)
    {
        $depth = 0;
        $length = strlen($css);
        $quote = null;
        $parens = 0;

        for ($i = $open; $i < $length; $i++) {
            $char = $css[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '\\') {
                $i++;
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '(') {
                $parens++;
                continue;
            }
            if ($char === ')') {
                $parens = max(0, $parens - 1);
                continue;
            }
            if ($parens > 0) {
                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return false;
    }
}
