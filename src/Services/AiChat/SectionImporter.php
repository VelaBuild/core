<?php

namespace VelaBuild\Core\Services\AiChat;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use VelaBuild\Core\Services\AiChat\Tools\FetchUrlTool;
use VelaBuild\Core\Services\BrowserRenderingService;

/**
 * Lift one section of a remote page — its markup, its styling, its pictures —
 * into something this site can render.
 *
 * Mapping a section onto page-builder blocks reproduces its arrangement and
 * loses its design, because a block looks the way this site's template says it
 * looks. Where the user asked for a copy rather than an arrangement, keeping
 * the section's own markup and its own (scoped) CSS is the only route that
 * actually resembles the original.
 */
class SectionImporter
{
    private const MAX_STYLESHEETS = 8;

    /**
     * Per-file ceiling. Generous on purpose: a utility framework ships one
     * stylesheet of several hundred KB, and cutting it short used to lose
     * every rule after the cut — the file is one giant @layer block, so an
     * unclosed brace took the whole thing down with it.
     */
    private const MAX_STYLESHEET_BYTES = 2_000_000;

    /** Ceiling across all of a page's stylesheets together. */
    private const MAX_CSS_TOTAL = 5_000_000;

    /** Attributes that would run code or reach back to the source site. */
    private const STRIP_ATTRIBUTES = ['srcset', 'data-src', 'data-lazy-src', 'data-original', 'integrity', 'crossorigin', 'nonce'];

    /**
     * @param  string      $url      page to import from
     * @param  string|null $selector CSS-ish selector ("#pricing", ".hero", "section")
     * @param  int|null    $index    1-based position from browse_url action "sections"
     */
    public function import(string $url, ?string $selector = null, ?int $index = null, bool $includeCss = true): array
    {
        $html = $this->fetchHtml($url);
        if (isset($html['error'])) {
            return $html;
        }

        $doc = $this->loadDocument($html['body']);
        if (!$doc) {
            return ['error' => 'Could not parse the page HTML.'];
        }

        $node = $this->locate($doc, $selector, $index);
        if (!$node) {
            return ['error' => $selector
                ? "No element matched '{$selector}' on {$url}. Call browse_url action \"sections\" to see what is there."
                : "Section {$index} is not on {$url}. Call browse_url action \"sections\" for the list."];
        }

        // One wrapper per SOURCE PAGE, not per section. Every section of a
        // page is styled by the same stylesheet, so a wrapper per section
        // meant storing five near-identical 110KB copies of it and blowing
        // the page's stylesheet budget on the fifth.
        $wrapper = 'vela-import-' . substr(md5($url), 0, 10);

        $markup = $this->extractMarkup($node, $url);
        // The wrapper is also the handle the page builder's design controls
        // write against, so it needs an id of its own — one per block, since
        // two sections from the same page must be restylable separately.
        $blockId = 'b' . substr(md5($url . '|' . ($selector ?? '') . '|' . ($index ?? '') . '|' . uniqid('', true)), 0, 10);
        $result = [
            'wrapper_class' => $wrapper,
            'block_id'      => $blockId,
            'html'          => '<div class="' . $wrapper . '" data-vela-block="' . $blockId . '">' . $markup['html'] . '</div>',
            'images'        => $markup['images'],
            'editable_fields' => $markup['fields'],
            'source_url'    => $url,
            // What the section is, so the caller can refuse to import the
            // parts of a page this site draws for itself.
            'tag'           => strtolower($node->tagName),
            'class'         => trim($node->getAttribute('class')),
            'id'            => trim($node->getAttribute('id')),
        ];

        if ($includeCss) {
            $css = $this->collectCss($doc, $url);
            // The unscoped stylesheet travels back too: the caller re-scopes it
            // against every section imported from this page together, so an
            // earlier section does not lose its styling when a later one is
            // added under the same wrapper.
            $result['raw_css'] = $css['css'];
            $scoped = app(CssScoper::class)->scope($css['css'], $result['html'], $wrapper, $url);
            $result['css'] = $scoped['css'];
            $result['css_rules_kept'] = $scoped['rules_kept'];
            $result['css_rules_dropped'] = $scoped['rules_dropped'];
            $result['css_truncated'] = $scoped['truncated'];
            $result['stylesheets_read'] = $css['sheets'];
            $result['stylesheets_failed'] = $css['failed'];
            $result['css_source_bytes'] = strlen($css['css']);
        }

        return $result;
    }

    /**
     * The same treatment, for markup written here rather than fetched.
     *
     * A section built out of page-builder blocks wears this site's own styling,
     * which is why a build from a picture came out recognisable but not alike:
     * the design reached the page through a dozen theme tokens and a fixed set
     * of block shapes. Markup written to match the design has no such ceiling,
     * and the reason it was refused before — that a section the model writes is
     * a section its owner can never touch — turns out to hold only for markup
     * written into a THEME. In a block it goes through the same field marking
     * as an imported section, so the page builder puts a plain form in front of
     * the wording, the pictures and the links.
     *
     * What is dropped here is what cannot be allowed in either case: anything
     * that executes, and anything that submits. A <style> goes too — the
     * stylesheet travels beside the markup so it can be scoped to this section,
     * and one left inline would reach every page it is rendered on.
     *
     * @param  string $key  distinguishes this section's wrapper from the rest
     *                      on the page; each gets its own, since each carries
     *                      its own stylesheet.
     * @return array{wrapper_class:string, block_id:string, html:string, images:array<int,string>, editable_fields:int}|array{error:string}
     */
    public function fromAuthoredMarkup(string $html, string $key): array
    {
        $doc = $this->loadDocument($html);
        if (!$doc) {
            return ['error' => 'That markup could not be parsed as HTML. Check the tags are balanced and try again.'];
        }

        $xpath = new DOMXPath($doc);

        foreach ($xpath->query('//script|//noscript|//style|//link|//meta|//iframe|//object|//embed|//base') ?: [] as $unwanted) {
            $unwanted->parentNode?->removeChild($unwanted);
        }

        foreach ($xpath->query('//form') ?: [] as $form) {
            if ($form instanceof DOMElement) {
                $form->removeAttribute('action');
                $form->removeAttribute('method');
            }
        }

        $images = [];
        foreach ($xpath->query('//*') ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                $name = strtolower($attribute->nodeName);

                if (str_starts_with($name, 'on')) {
                    $element->removeAttribute($attribute->nodeName);
                    continue;
                }

                if ($name === 'href' && str_starts_with(strtolower(trim($attribute->nodeValue ?? '')), 'javascript:')) {
                    $element->setAttribute('href', '#');
                }
            }

            if (strtolower($element->tagName) === 'img') {
                $src = trim($element->getAttribute('src'));
                if ($src !== '' && !str_starts_with($src, 'data:')) {
                    $images[$src] = true;
                }
            }
        }

        $fields = $this->markEditableFields($doc);

        $inner = '';
        foreach ($doc->getElementsByTagName('body')->item(0)?->childNodes ?? [] as $child) {
            $inner .= $doc->saveHTML($child);
        }

        $wrapper = 'vela-design-' . substr(md5($key), 0, 10);
        $blockId = 'b' . substr(md5($key . '|' . uniqid('', true)), 0, 10);

        // What the section is, so the caller can refuse the parts of a page
        // this site draws for itself on every page from its own theme.
        $root = null;
        foreach ($doc->getElementsByTagName('body')->item(0)?->childNodes ?? [] as $child) {
            if ($child instanceof DOMElement) {
                $root = $root === null ? $child : false;
            }
        }

        return [
            'tag'             => $root instanceof DOMElement ? strtolower($root->tagName) : '',
            'class'           => $root instanceof DOMElement ? trim($root->getAttribute('class')) : '',
            'wrapper_class'   => $wrapper,
            'block_id'        => $blockId,
            'html'            => '<div class="' . $wrapper . '" data-vela-block="' . $blockId . '">' . trim($inner) . '</div>',
            'images'          => array_keys($images),
            'editable_fields' => $fields,
        ];
    }

    /**
     * Pages and stylesheets already fetched in this process.
     *
     * Copying a page means one call per section, and each was re-downloading
     * the page and its megabyte of stylesheets from scratch — nine sections
     * meant nine round trips for identical bytes.
     *
     * @var array<string, array{at:int, value:array}>
     */
    private static array $cache = [];

    /**
     * How long a fetch is reused. Short on purpose: a queue worker stays up
     * for hours, and a copy started later must see the page as it is now, not
     * as it was when someone copied it this morning.
     */
    private const CACHE_TTL = 300;

    /** Drop everything cached — for tests, and for a deliberate re-read. */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    private static function cached(string $key): ?array
    {
        $entry = self::$cache[$key] ?? null;
        if ($entry === null || time() - $entry['at'] > self::CACHE_TTL) {
            unset(self::$cache[$key]);
            return null;
        }

        return $entry['value'];
    }

    private static function remember(string $key, array $value): void
    {
        self::$cache = array_slice(self::$cache, -8, null, true);
        self::$cache[$key] = ['at' => time(), 'value' => $value];
    }

    /** @return array{body:string}|array{error:string} */
    private function fetchHtml(string $url): array
    {
        if ($hit = self::cached('html:' . $url)) {
            return $hit;
        }

        $result = $this->fetchHtmlUncached($url);

        // Only successes are held: a transient failure should not be the
        // answer for the rest of the conversation.
        if (!isset($result['error'])) {
            self::remember('html:' . $url, $result);
        }

        return $result;
    }

    /** @return array{body:string}|array{error:string} */
    private function fetchHtmlUncached(string $url): array
    {
        // Prefer the rendered DOM where a browser is available: sites that
        // build their sections in JavaScript serve almost nothing useful.
        $renderer = app(BrowserRenderingService::class);
        if ($renderer->isConfigured()) {
            $rendered = $renderer->html($url);
            if ($rendered) {
                return ['body' => $rendered];
            }
        }

        // Through FetchUrlTool so the SSRF guard applies as it does elsewhere.
        $result = app(FetchUrlTool::class)->execute(['url' => $url]);
        if (!empty($result['error'])) {
            return ['error' => $result['error']];
        }
        if (($result['status'] ?? 0) >= 400) {
            return ['error' => 'HTTP ' . $result['status'] . ' fetching ' . $url];
        }

        return ['body' => (string) ($result['body'] ?? '')];
    }

    private function loadDocument(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $doc : null;
    }

    private function locate(DOMDocument $doc, ?string $selector, ?int $index): ?DOMElement
    {
        if ($selector !== null && trim($selector) !== '') {
            $xpath = new DOMXPath($doc);
            $selector = trim($selector);
            if (str_starts_with($selector, '#')) {
                $query = '//*[@id="' . substr($selector, 1) . '"]';
            } elseif (str_starts_with($selector, '.')) {
                $query = '//*[contains(concat(" ", normalize-space(@class), " "), " ' . substr($selector, 1) . ' ")]';
            } elseif (preg_match('/^[a-z][a-z0-9]*$/i', $selector)) {
                $query = '//' . strtolower($selector);
            } else {
                return null;
            }

            $node = $xpath->query($query)?->item(0);
            return $node instanceof DOMElement ? $node : null;
        }

        if ($index !== null && $index > 0) {
            // Same walk browse_url action "sections" reports, so the numbers
            // the model was given line up with what gets imported.
            $sections = app(PageSectionExtractor::class)->sectionNodes($doc);
            return $sections[$index - 1] ?? null;
        }

        return null;
    }

    /**
     * Copy the node's markup, dropping anything that would execute, phone home,
     * or fight with this site's own layout, and making every address absolute.
     *
     * @return array{html:string, images:array<int,string>}
     */
    private function extractMarkup(DOMElement $node, string $url): array
    {
        $scratch = new DOMDocument();
        $imported = $scratch->importNode($node->cloneNode(true), true);
        $scratch->appendChild($this->withAncestors($scratch, $imported, $node));

        $xpath = new DOMXPath($scratch);

        foreach ($xpath->query('//script|//noscript|//link|//meta|//iframe|//object|//embed|//base') ?: [] as $unwanted) {
            $unwanted->parentNode?->removeChild($unwanted);
        }

        // A form here would post to the other site — or to this one, where the
        // route does not exist. Keep the fields visible, lose the submission.
        foreach ($xpath->query('//form') ?: [] as $form) {
            if ($form instanceof DOMElement) {
                $form->removeAttribute('action');
                $form->removeAttribute('method');
            }
        }

        $images = [];
        foreach ($xpath->query('//*') ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                $name = strtolower($attribute->nodeName);

                // Inline event handlers are executable content.
                if (str_starts_with($name, 'on')) {
                    $element->removeAttribute($attribute->nodeName);
                    continue;
                }

                if ($name === 'href' && str_starts_with(strtolower(trim($attribute->nodeValue ?? '')), 'javascript:')) {
                    $element->setAttribute('href', '#');
                    continue;
                }

                if (in_array($name, self::STRIP_ATTRIBUTES, true)) {
                    // A lazy-loading placeholder only becomes a picture once
                    // the source site's JavaScript runs, which it never will
                    // here — so promote the real file into src and drop the
                    // attribute that pointed at it.
                    if ($name !== 'srcset' && $element->tagName === 'img') {
                        $real = trim((string) $attribute->nodeValue);
                        $current = trim($element->getAttribute('src'));
                        if ($real !== '' && ($current === '' || str_starts_with($current, 'data:'))) {
                            $element->setAttribute('src', $real);
                        }
                    } elseif ($name === 'srcset' && $element->tagName === 'img') {
                        $current = trim($element->getAttribute('src'));
                        if ($current === '' || str_starts_with($current, 'data:')) {
                            $first = trim(explode(' ', trim(explode(',', (string) $attribute->nodeValue)[0]))[0]);
                            if ($first !== '') {
                                $element->setAttribute('src', $first);
                            }
                        }
                    }
                    $element->removeAttribute($attribute->nodeName);
                }
            }

            foreach (['src', 'href', 'poster'] as $attribute) {
                $value = $element->getAttribute($attribute);
                if ($value !== '') {
                    $element->setAttribute($attribute, CssScoper::absolutize($value, $url));
                }
            }

            $style = $element->getAttribute('style');
            if ($style !== '' && str_contains($style, 'url(')) {
                $element->setAttribute('style', preg_replace_callback(
                    '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
                    fn ($m) => 'url("' . CssScoper::absolutize($m[2], $url) . '")',
                    $style
                ) ?? $style);
            }

            if ($element->tagName === 'img') {
                $src = $element->getAttribute('src');
                if ($src !== '' && !str_starts_with($src, 'data:')) {
                    $images[$src] = true;
                }
            }
        }

        $fields = $this->markEditableFields($scratch);

        return [
            'html'   => trim((string) $scratch->saveHTML()),
            'images' => array_keys($images),
            'fields' => $fields,
        ];
    }

    /**
     * Tag the parts of the section a non-technical person will want to change.
     *
     * An imported section is raw markup, and the page builder can only offer
     * a textarea full of HTML for it — which means the person whose site it is
     * cannot touch their own page. Marking each piece of wording, each picture
     * and each link here lets the editor put a plain form in front of them
     * instead, while the markup underneath keeps the design it was imported
     * with.
     *
     * @return int number of editable fields marked
     */
    private function markEditableFields(DOMDocument $doc): int
    {
        $xpath = new DOMXPath($doc);
        $index = 0;

        $this->markGrids($xpath);

        foreach ($xpath->query('//*') ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($element->tagName);
            $kinds = [];

            if ($tag === 'img') {
                $kinds[] = 'image';
            }

            if ($tag === 'a' && $element->getAttribute('href') !== '') {
                $kinds[] = 'link';
            }

            // A form control holds no words of its own, so nothing marked it
            // and the page builder offered no way to reword the placeholder
            // or to leave the field out — on a copied contact form, the two
            // things anyone wants to do.
            if (in_array($tag, ['input', 'select', 'textarea'], true)
                && !in_array(strtolower($element->getAttribute('type')), ['hidden', 'submit', 'button', 'reset', 'image'], true)) {
                $kinds[] = 'control';
            }

            // Wording is editable on the element that directly holds it: a
            // heading, a paragraph, a button label. An element with children
            // of its own is a container, and rewriting its text would throw
            // that structure away.
            //
            // A line break is the exception. "Scale your app,<br>control your
            // costs" is one heading with one <br> in it, and treating that as
            // a container left the whole section with nothing editable at all
            // — the page's headline, of all things. Those are marked, and the
            // editor keeps the break as a newline in the text box.
            $elementChildren = [];
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $elementChildren[] = strtolower($child->tagName);
                }
            }
            $breaksOnly = $elementChildren !== [] && array_unique($elementChildren) === ['br'];

            $text = trim(preg_replace('/\s+/u', ' ', $element->textContent) ?? '');
            if (($elementChildren === [] || $breaksOnly)
                && $text !== ''
                && !in_array($tag, ['script', 'style', 'img', 'br', 'input'], true)) {
                $kinds[] = 'text';
                if ($breaksOnly) {
                    $element->setAttribute('data-vela-field-multiline', '1');
                }
            }

            // Anything a visitor would point at can be given a link, whether or
            // not whoever wrote the markup made it one. A card, a bullet, a
            // heading and a picture were all unlinkable before this, and the
            // whole card is what people expect to click.
            if (!in_array('link', $kinds, true) && $this->couldCarryALink($element, $kinds)) {
                $kinds[] = 'linkable';
            }

            if ($kinds === []) {
                continue;
            }

            $index++;
            $element->setAttribute('data-vela-field', 'f' . $index);
            $element->setAttribute('data-vela-field-kind', implode(' ', $kinds));
        }

        return $index;
    }

    /**
     * Whether a link can be put on this element without breaking the markup.
     *
     * An <a> inside an <a> is not something a browser repairs — it closes the
     * outer one early and the second half of the card stops being clickable —
     * so anything already within a link is left alone.
     *
     * A card or a bullet qualifies as a whole even though it is a container,
     * because a whole card going somewhere is what a visitor expects of one.
     * Where it already holds a link of its own that link is the answer, and
     * wrapping the card around it would only give the same destination twice.
     */
    private function couldCarryALink(DOMElement $element, array $kinds): bool
    {
        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (strtolower($node->tagName) === 'a') {
                return false;
            }
        }

        $isWhole = $element->hasAttribute('data-vela-card') || strtolower($element->tagName) === 'li';

        if ($isWhole) {
            $inside = (new DOMXPath($element->ownerDocument))->query('.//a[@href]', $element);

            return $inside === false || $inside->length === 0;
        }

        return in_array('text', $kinds, true) || in_array('image', $kinds, true);
    }

    /**
     * Mark the rows of repeated cards, so the page builder can offer a
     * "columns" control for them.
     *
     * How many cards sit across a row is the change people ask for first —
     * three logos instead of six, two pricing tiers instead of four — and it
     * is buried in a class name like `grid-cols-3` that nobody outside the
     * source site's build system would recognise.
     */
    private function markGrids(DOMXPath $xpath): void
    {
        $index = 0;

        foreach ($xpath->query('//*') ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            $children = [];
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $children[] = $child;
                }
            }

            if (count($children) < 2) {
                continue;
            }

            // Siblings that share a tag and a class list are a repeated set,
            // whatever the framework calls them.
            $signature = null;
            foreach ($children as $child) {
                $classes = preg_split('/\s+/', trim($child->getAttribute('class'))) ?: [];
                sort($classes);
                $current = strtolower($child->tagName) . '|' . implode(' ', array_slice(array_filter($classes), 0, 4));
                if ($signature === null) {
                    $signature = $current;
                } elseif ($signature !== $current) {
                    $signature = false;
                    break;
                }
            }

            if ($signature === false || $signature === null || $signature === '') {
                continue;
            }

            $index++;
            $element->setAttribute('data-vela-grid', 'g' . $index);
            $element->setAttribute('data-vela-grid-count', (string) count($children));

            // And each child is a card: the thing a visitor points at. Nothing
            // named it before, so the editor called it "Block", it could not be
            // made clickable as a whole, and someone wanting the third card to
            // go somewhere had to reach past it to the heading inside.
            foreach ($children as $position => $child) {
                $child->setAttribute('data-vela-card', 'c' . $index . '-' . ($position + 1));
            }
        }
    }

    /**
     * Rebuild the chain of wrappers the section sat inside.
     *
     * A section is not styled by its own classes alone. Its width comes from a
     * container div above it, its typeface from a class on <html>, its dark
     * mode from one on <body> — and, on anything built with a modern utility
     * framework, every `@md:` / `@lg:` class is a CONTAINER query that matches
     * nothing at all unless an ancestor declares itself a container. Lifted
     * out on its own, a section therefore renders as unstyled text in a narrow
     * column no matter how much of the stylesheet came with it.
     *
     * So the ancestors come too — as plain divs carrying their classes, their
     * inline styles and their data attributes, which is everything the CSS
     * looks at.
     */
    private function withAncestors(DOMDocument $scratch, \DOMNode $inner, DOMElement $original): \DOMNode
    {
        $current = $inner;
        $depth = 0;

        for ($parent = $original->parentNode; $parent instanceof DOMElement && $depth < 12; $parent = $parent->parentNode) {
            $class = trim($parent->getAttribute('class'));
            $style = trim($parent->getAttribute('style'));
            $data = [];
            foreach ($parent->attributes ?? [] as $attribute) {
                if (str_starts_with(strtolower($attribute->nodeName), 'data-')) {
                    $data[$attribute->nodeName] = $attribute->nodeValue;
                }
            }

            // A wrapper with nothing on it styles nothing; skipping it keeps
            // the imported markup as shallow as it can be.
            if ($class === '' && $style === '' && $data === []) {
                continue;
            }

            // Always a div: <html>, <body> and <main> have meanings a fragment
            // inside a page must not claim, and no CSS selects on them here —
            // the rules that did were rewritten onto the wrapper.
            $wrapper = $scratch->createElement('div');
            if ($class !== '') {
                $wrapper->setAttribute('class', $class);
            }
            if ($style !== '') {
                $wrapper->setAttribute('style', $style);
            }
            foreach ($data as $name => $value) {
                $wrapper->setAttribute($name, (string) $value);
            }

            $wrapper->appendChild($current);
            $current = $wrapper;
            $depth++;
        }

        return $current;
    }

    /**
     * Gather the page's stylesheets: inline <style> blocks plus the linked
     * files, which is where the section's real design lives.
     *
     * @return array{css:string, sheets:array<int,string>, failed:array<int,string>}
     */
    private function collectCss(DOMDocument $doc, string $url): array
    {
        if ($hit = self::cached('css:' . $url)) {
            return $hit;
        }

        $collected = $this->collectCssUncached($doc, $url);
        self::remember('css:' . $url, $collected);

        return $collected;
    }

    /** @return array{css:string, sheets:array<int,string>, failed:array<int,string>} */
    private function collectCssUncached(DOMDocument $doc, string $url): array
    {
        $css = '';
        foreach ($doc->getElementsByTagName('style') as $style) {
            $css .= "\n" . $style->textContent;
        }

        $sheets = [];
        $failed = [];
        foreach ($this->stylesheetUrls($doc, $url) as $absolute) {
            if (count($sheets) >= self::MAX_STYLESHEETS) {
                break;
            }

            $body = $this->fetchStylesheet($absolute, $url);
            if ($body === null) {
                $failed[] = $absolute;
                continue;
            }

            $sheets[] = $absolute;
            // Addresses inside a stylesheet are relative to that file, not to
            // the page — resolve them here, while the file's own URL is known.
            $css .= "\n" . CssScoper::absolutizeUrls($this->capStylesheet($body), $absolute);

            if (strlen($css) >= self::MAX_CSS_TOTAL) {
                break;
            }
        }

        // Sites behind a CDN or a bot check hand a plain HTTP client a 403 for
        // their stylesheets while serving them happily to a browser. Where a
        // browser is configured, ask it for the CSS it already has.
        if (trim($css) === '' && ($sheets === [] || $failed !== [])) {
            $viaBrowser = $this->cssViaBrowser($url);
            if ($viaBrowser !== null && trim($viaBrowser) !== '') {
                $css .= "\n" . CssScoper::absolutizeUrls($viaBrowser, $url);
                $sheets[] = 'browser:document.styleSheets';
                $failed = [];
            }
        }

        return ['css' => $css, 'sheets' => $sheets, 'failed' => $failed];
    }

    /** Cut an oversized stylesheet on a rule boundary rather than mid-block. */
    private function capStylesheet(string $body): string
    {
        if (strlen($body) <= self::MAX_STYLESHEET_BYTES) {
            return $body;
        }

        $cut = strrpos(substr($body, 0, self::MAX_STYLESHEET_BYTES), '}');

        return $cut === false ? '' : substr($body, 0, $cut + 1);
    }

    /**
     * Every stylesheet the page pulls in.
     *
     * Matching rel="stylesheet" exactly missed most real pages: rel carries
     * several tokens ("stylesheet preload"), casing varies, and build tools
     * emit <link rel="preload" as="style"> for the main sheet. Print-only
     * sheets are skipped — they style paper, not the section.
     *
     * @return array<int, string>
     */
    private function stylesheetUrls(DOMDocument $doc, string $url): array
    {
        $urls = [];
        foreach ($doc->getElementsByTagName('link') as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $rel = strtolower(trim($link->getAttribute('rel')));
            $as = strtolower(trim($link->getAttribute('as')));
            $relTokens = preg_split('/\s+/', $rel) ?: [];
            $isStylesheet = in_array('stylesheet', $relTokens, true)
                || ($as === 'style' && in_array('preload', $relTokens, true));
            if (!$isStylesheet) {
                continue;
            }

            $media = strtolower(trim($link->getAttribute('media')));
            if ($media === 'print') {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $absolute = CssScoper::absolutize($href, $url);
            $urls[$absolute] = true;
        }

        return array_keys($urls);
    }

    private function fetchStylesheet(string $stylesheetUrl, string $pageUrl): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    // A bare client UA is what most CDNs answer with a 403;
                    // asking the way a browser asks gets the file.
                    'User-Agent' => 'Mozilla/5.0 (compatible; VelaBuild-AI-Helper/1.0; +https://velabuild.com)',
                    'Accept'     => 'text/css,*/*;q=0.1',
                    'Referer'    => $pageUrl,
                ])
                ->withOptions(['allow_redirects' => ['max' => 5]])
                ->get($stylesheetUrl);
        } catch (\Throwable $e) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    /** Read the stylesheets the headless browser already parsed. */
    private function cssViaBrowser(string $url): ?string
    {
        $renderer = app(BrowserRenderingService::class);
        if (!$renderer->isConfigured()) {
            return null;
        }

        $result = $renderer->collectStylesheets($url);

        return is_string($result) ? $result : null;
    }
}
