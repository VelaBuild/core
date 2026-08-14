<?php

namespace VelaBuild\Core\Services\AiChat;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Turn a page's HTML into a section-by-section outline.
 *
 * Copying a page used to mean handing the model raw markup and hoping it
 * spotted the structure. It rarely did: minified markup buries the outline,
 * and the model would rebuild three sections out of nine and call it done.
 * This walks the document instead and reports what each top-level section
 * actually holds — heading, supporting line, buttons, how many repeated
 * cards, which images — so rebuilding it is a mechanical mapping rather
 * than a guess, and so the count of sections can be checked afterwards.
 */
class PageSectionExtractor
{
    /** Sections shorter than this and carrying nothing visual are dropped. */
    private const MIN_TEXT = 12;

    public function extract(string $html, string $pageUrl = ''): array
    {
        $doc = $this->loadDocument($html);
        if (!$doc) {
            return ['sections' => [], 'error' => 'Could not parse HTML'];
        }

        $xpath = new DOMXPath($doc);
        $this->stripNoise($xpath);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            return ['sections' => [], 'error' => 'No body element'];
        }

        $base = $this->baseUrl($pageUrl);
        $sections = [];
        foreach ($this->topLevelSections($body) as $i => $node) {
            $section = $this->describeSection($node, $i, $base, $pageUrl);
            if ($section !== null) {
                $sections[] = $section;
            }
        }

        // Renumber after the drops so `index` matches position, which is what
        // the model builds against and what the completeness check counts.
        // The block suggestion waits until now for the same reason: "first
        // section on the page" is what makes a headline over two buttons a
        // hero rather than a closing call to action, and that is only known
        // once the empty wrappers have been dropped.
        foreach ($sections as $i => &$section) {
            $section['index'] = $i + 1;
            $section['suggested_block'] = $this->suggestBlock($section, $i, $section['tag']);
        }
        unset($section);

        return [
            'section_count' => count($sections),
            'sections'      => $sections,
        ];
    }

    /**
     * The same sections `extract()` numbers, as nodes.
     *
     * An importer works from the numbers the model was shown, so the two have
     * to agree on what counts as a section — including the empty wrappers
     * dropped along the way, which is what shifts every later index. The
     * document is cloned before the noise is stripped so the caller's own copy
     * (and its <style> tags) survives.
     *
     * @return DOMElement[] 0-based; section N from extract() is index N-1
     */
    public function sectionNodes(DOMDocument $doc): array
    {
        $clone = clone $doc;
        $this->stripNoise(new DOMXPath($clone));

        $body = $clone->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            return [];
        }

        $kept = [];
        foreach ($this->topLevelSections($body) as $node) {
            if ($this->isSubstantial($node)) {
                $kept[] = $node;
            }
        }

        return $kept;
    }

    /** Does this element hold enough to be a section of its own? */
    private function isSubstantial(DOMElement $node): bool
    {
        return mb_strlen($this->normalize($node->textContent)) >= self::MIN_TEXT
            || $node->getElementsByTagName('img')->length > 0
            || $node->getElementsByTagName('form')->length > 0
            || $this->buttons($node) !== [];
    }

    private function loadDocument(string $html): ?DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        // Without the meta hint libxml reads the bytes as ISO-8859-1 and every
        // non-ASCII heading comes back mojibake — which then gets written into
        // the rebuilt page verbatim.
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $doc : null;
    }

    /** Remove nodes that carry no layout meaning but plenty of tokens. */
    private function stripNoise(DOMXPath $xpath): void
    {
        $nodes = $xpath->query('//script|//style|//noscript|//svg|//template|//comment()');
        foreach ($nodes ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * Find the elements that read as the page's top-level sections.
     *
     * Sites wrap their content in several layers of layout divs, so the
     * children of <body> are usually one wrapper, not the sections. Descend
     * through single-child wrappers, and expand <main> in place so its
     * sections sit alongside the header and footer rather than collapsing
     * into one giant entry.
     *
     * @return DOMElement[]
     */
    private function topLevelSections(DOMElement $body): array
    {
        $container = $body;
        for ($depth = 0; $depth < 5; $depth++) {
            $children = $this->elementChildren($container);
            if (count($children) !== 1) {
                break;
            }
            $only = $children[0];
            if (in_array(strtolower($only->tagName), ['header', 'footer', 'main', 'section', 'nav'], true)) {
                break;
            }
            $container = $only;
        }

        $sections = [];
        foreach ($this->elementChildren($container) as $child) {
            if (strtolower($child->tagName) === 'main') {
                foreach ($this->elementChildren($child) as $inner) {
                    $sections[] = $inner;
                }
                continue;
            }
            $sections[] = $child;
        }

        return $this->splitDominantSection($sections);
    }

    /**
     * Break open a "section" that is really the whole page.
     *
     * An app-shell site (Next.js and friends) wraps everything in a handful of
     * layout divs with no <main> and no <section> at the top, so the walk above
     * comes back with one entry holding the entire page. Reported as an
     * outline that is useless — one line for a nine-section page — and copied
     * as a single 190KB block that no amount of styling can make look right.
     *
     * @param  DOMElement[] $sections
     * @return DOMElement[]
     */
    private function splitDominantSection(array $sections): array
    {
        for ($pass = 0; $pass < 4; $pass++) {
            // <main> is the page body wherever it turns up — including one
            // uncovered by an earlier pass — and its children are the sections.
            foreach ($sections as $i => $section) {
                if (strtolower($section->tagName) !== 'main') {
                    continue;
                }
                $children = $this->unwrapSingleChildren($section);
                // …unless <main> holds a single row of columns, which is one
                // section (a headline beside a form), not two.
                if (count($children) >= 2 && !$this->areColumnsOfOneRow($children)) {
                    array_splice($sections, $i, 1, $children);
                    continue 2;
                }
            }

            $lengths = array_map(fn ($node) => mb_strlen($this->normalize($node->textContent)), $sections);
            $total = array_sum($lengths);
            if ($total === 0) {
                return $sections;
            }

            $index = null;
            foreach ($lengths as $i => $length) {
                // One entry carrying the bulk of the page is a wrapper, not a
                // section — unless it says outright that it is a section, in
                // which case a short page really does have one.
                $tag = strtolower($sections[$i]->tagName);
                if (in_array($tag, ['section', 'article', 'aside', 'header', 'footer', 'nav'], true)) {
                    continue;
                }
                if ($length >= $total * 0.7 && count($sections) < 40) {
                    $index = $i;
                    break;
                }
            }

            if ($index === null) {
                return $sections;
            }

            $children = $this->unwrapSingleChildren($sections[$index]);

            // Only split where the children are containers in their own right.
            // A wrapper whose children are a heading and two paragraphs is one
            // section written without a <section> tag, and breaking it apart
            // would report each paragraph as a section of the page.
            $containers = array_filter($children, fn ($child) => $this->elementChildren($child) !== []);
            if (count($children) < 2 || count($containers) < 2) {
                return $sections;
            }

            // Columns of one row are not sections. A "talk to sales" page is a
            // headline beside a form; split apart, each became a full-width
            // row of its own and the copy read as two stacked blocks instead
            // of the two-column section the page actually is.
            if ($this->areColumnsOfOneRow($children)) {
                return $sections;
            }

            array_splice($sections, $index, 1, $children);
        }

        return $sections;
    }

    /**
     * Do these siblings read as columns placed side by side?
     *
     * Judged from the placement classes a layout framework puts on a column —
     * col-start / col-span / basis / w-1/2 / flex-1. Deliberately narrow: the
     * outermost shell of an app is a flex container too, and refusing to
     * split THAT would leave the whole page as one section again.
     *
     * @param DOMElement[] $children
     */
    private function areColumnsOfOneRow(array $children): bool
    {
        $columns = 0;
        foreach ($children as $child) {
            $class = strtolower($child->getAttribute('class'));
            if ($class !== '' && preg_match('#(^|[\s:])(col-span|col-start|col-end|basis-|flex-1|w-1/2|w-1/3|w-2/3)#', $class)) {
                $columns++;
            }
        }

        return $columns >= 2;
    }

    /** Descend through wrappers that hold exactly one element. */
    private function unwrapSingleChildren(DOMElement $node): array
    {
        $current = $node;
        for ($depth = 0; $depth < 6; $depth++) {
            $children = $this->elementChildren($current);
            if (count($children) !== 1) {
                break;
            }
            $current = $children[0];
        }

        $children = $this->elementChildren($current);

        // <main> here is the page body, and its children are the sections.
        if (count($children) === 1 && strtolower($children[0]->tagName) === 'main') {
            $children = $this->elementChildren($children[0]);
        }

        return $children;
    }

    /** @return DOMElement[] */
    private function elementChildren(DOMNode $node): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }
        return $children;
    }

    private function describeSection(DOMElement $node, int $index, string $base, string $pageUrl): ?array
    {
        $tag = strtolower($node->tagName);
        $text = $this->normalize($node->textContent);
        $images = $this->images($node, $base, $pageUrl);
        $buttons = $this->buttons($node);
        $hasForm = $node->getElementsByTagName('form')->length > 0;

        // One rule for what counts as a section, shared with sectionNodes() —
        // if the two disagree by a single drop, every index after it points at
        // the wrong part of the page.
        if (!$this->isSubstantial($node)) {
            return null;
        }

        $headings = $this->headings($node);
        $repeat = $this->repeatedGroup($node);
        $lead = $this->leadParagraph($node);

        $section = [
            'index'        => $index + 1,
            'tag'          => $tag,
            'id'           => $node->getAttribute('id') ?: null,
            'class'        => $this->shorten($node->getAttribute('class'), 160) ?: null,
            'heading'      => $headings[0]['text'] ?? null,
            'heading_level'=> $headings[0]['level'] ?? null,
            'subheadings'  => array_values(array_map(
                fn ($h) => $h['text'],
                array_slice(array_filter($headings, fn ($h) => $h !== ($headings[0] ?? null)), 0, 8)
            )),
            'lead_text'    => $lead,
            'buttons'      => $buttons,
            'images'       => array_slice($images, 0, 12),
            'image_count'  => count($images),
            'has_form'     => $hasForm,
            'text_chars'   => mb_strlen($text),
            'text'         => $this->shorten($text, 1200),
        ];

        if ($repeat) {
            $section['repeated_items'] = $repeat;
        }

        return array_filter(
            $section,
            fn ($v) => $v !== null && $v !== [] && $v !== ''
        );
    }

    /** @return array<int, array{level:int, text:string}> */
    private function headings(DOMElement $node): array
    {
        $found = [];
        foreach (['h1', 'h2', 'h3', 'h4'] as $tag) {
            foreach ($node->getElementsByTagName($tag) as $h) {
                $text = $this->normalize($h->textContent);
                if ($text !== '') {
                    $found[] = ['level' => (int) substr($tag, 1), 'text' => $this->shorten($text, 200)];
                }
            }
        }

        usort($found, fn ($a, $b) => $a['level'] <=> $b['level']);
        return array_slice($found, 0, 12);
    }

    private function leadParagraph(DOMElement $node): ?string
    {
        foreach ($node->getElementsByTagName('p') as $p) {
            $text = $this->normalize($p->textContent);
            if (mb_strlen($text) >= 30) {
                return $this->shorten($text, 400);
            }
        }
        return null;
    }

    /** @return array<int, array{text:string, href:?string}> */
    private function buttons(DOMElement $node): array
    {
        $buttons = [];
        foreach (['a', 'button'] as $tag) {
            foreach ($node->getElementsByTagName($tag) as $el) {
                $text = $this->normalize($el->textContent);
                if ($text === '' || mb_strlen($text) > 60) {
                    continue;
                }
                $class = strtolower($el->getAttribute('class'));
                $isButton = $tag === 'button'
                    || str_contains($class, 'btn')
                    || str_contains($class, 'button')
                    || str_contains($class, 'cta');
                if (!$isButton) {
                    continue;
                }
                $buttons[] = ['text' => $text, 'href' => $el->getAttribute('href') ?: null];
                if (count($buttons) >= 8) {
                    return $buttons;
                }
            }
        }
        return $buttons;
    }

    /** @return array<int, array{src:string, alt:?string}> */
    private function images(DOMElement $node, string $base, string $pageUrl): array
    {
        $images = [];
        $seen = [];
        foreach ($node->getElementsByTagName('img') as $img) {
            // Lazy-loading themes leave src as a placeholder and put the real
            // file in data-src / srcset; taking src alone downloads a 1px gif.
            $src = $img->getAttribute('src');
            foreach (['data-src', 'data-lazy-src', 'data-original'] as $attr) {
                $candidate = $img->getAttribute($attr);
                if ($candidate !== '' && ($src === '' || str_starts_with($src, 'data:'))) {
                    $src = $candidate;
                }
            }
            if ($src === '' || str_starts_with($src, 'data:')) {
                $srcset = $img->getAttribute('srcset');
                if ($srcset !== '') {
                    $src = trim(explode(' ', trim(explode(',', $srcset)[0]))[0]);
                }
            }
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }
            $src = $this->resolveUrl($src, $base, $pageUrl);
            if (isset($seen[$src])) {
                continue;
            }
            $seen[$src] = true;
            $images[] = ['src' => $src, 'alt' => $img->getAttribute('alt') ?: null];
        }
        return $images;
    }

    /**
     * Spot the repeated card group inside a section.
     *
     * Three feature cards and three pricing tiers look nothing alike in the
     * markup, but both are "one container, N siblings sharing a class". The
     * count is the number the rebuilt block needs, and it is the detail most
     * often lost when the model eyeballs the HTML.
     */
    private function repeatedGroup(DOMElement $node): ?array
    {
        $best = null;

        $walk = function (DOMElement $el, int $depth) use (&$walk, &$best) {
            if ($depth > 6) {
                return;
            }
            $children = $this->elementChildren($el);
            if (count($children) >= 2) {
                $signatures = [];
                foreach ($children as $child) {
                    $signatures[] = strtolower($child->tagName) . '|' . $this->classSignature($child);
                }
                $counts = array_count_values($signatures);
                arsort($counts);
                $topSignature = (string) array_key_first($counts);
                $topCount = $counts[$topSignature];

                if ($topCount >= 2 && $topCount === count($children)) {
                    $titles = [];
                    foreach ($children as $child) {
                        $titles[] = $this->shorten($this->cardTitle($child), 120);
                    }
                    $titles = array_values(array_filter($titles));
                    if (count($titles) >= 2 && (!$best || $topCount > $best['count'])) {
                        $best = [
                            'count'  => $topCount,
                            'titles' => array_slice($titles, 0, 12),
                        ];
                    }
                }
            }
            foreach ($children as $child) {
                $walk($child, $depth + 1);
            }
        };

        $walk($node, 0);

        return $best;
    }

    private function cardTitle(DOMElement $el): string
    {
        foreach (['h2', 'h3', 'h4', 'h5', 'strong'] as $tag) {
            $first = $el->getElementsByTagName($tag)->item(0);
            if ($first) {
                $text = $this->normalize($first->textContent);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return $this->normalize($el->textContent);
    }

    private function classSignature(DOMElement $el): string
    {
        $classes = preg_split('/\s+/', trim($el->getAttribute('class'))) ?: [];
        sort($classes);
        return implode(' ', array_slice(array_filter($classes), 0, 4));
    }

    /**
     * Name the page-builder block that fits this section.
     *
     * A hint, not a verdict — the model still picks, but without one it tends
     * to flatten every section into a text block, which is exactly the "not
     * detailed enough" result.
     */
    private function suggestBlock(array $section, int $index, string $tag): string
    {
        $haystack = strtolower(($section['heading'] ?? '') . ' ' . ($section['class'] ?? '') . ' ' . ($section['id'] ?? ''));
        $repeatCount = $section['repeated_items']['count'] ?? 0;
        $heading = $section['heading'] ?? null;
        $buttons = $section['buttons'] ?? [];

        if (!empty($section['has_form'])) {
            return 'contact_form';
        }
        if ($tag === 'nav' || str_contains($haystack, 'navbar')) {
            return 'skip (site navigation — belongs to the template, not a block)';
        }
        if ($tag === 'footer' || str_contains($haystack, 'footer')) {
            return 'skip (site footer — belongs to the template, not a block)';
        }
        if ($tag === 'header' && $index === 0 && !$heading) {
            return 'skip (site header — belongs to the template, not a block)';
        }
        if (preg_match('/\b(price|pricing|plan|tier|\$\d)/i', $haystack) && $repeatCount >= 2) {
            return 'pricing_tiers';
        }
        if (preg_match('/(testimonial|review|what .* say|client)/i', $haystack)) {
            return 'testimonials';
        }
        if (preg_match('/(faq|question)/i', $haystack)) {
            return 'accordion';
        }
        if (preg_match('/(gallery|portfolio)/i', $haystack) && ($section['image_count'] ?? 0) >= 3) {
            return 'gallery';
        }
        if (preg_match('/(blog|news|article|post)/i', $haystack) && $repeatCount >= 2) {
            return 'posts_grid';
        }
        if ($index === 0 && $heading && $buttons) {
            return 'hero';
        }
        if ($repeatCount >= 2) {
            return 'icon_box';
        }
        if ($heading && $buttons && ($section['text_chars'] ?? 0) < 400) {
            return 'cta';
        }
        if (($section['image_count'] ?? 0) >= 3) {
            return 'gallery';
        }
        if (($section['image_count'] ?? 0) === 1 && ($section['text_chars'] ?? 0) < 200) {
            return 'image';
        }
        return 'text';
    }

    private function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    private function shorten(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }

    private function baseUrl(string $pageUrl): string
    {
        $parts = parse_url($pageUrl);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function resolveUrl(string $href, string $base, string $pageUrl): string
    {
        if ($base === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            return (parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https') . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $base . $href;
        }
        $path = parse_url($pageUrl, PHP_URL_PATH) ?: '/';
        return $base . rtrim(dirname($path), '/') . '/' . $href;
    }
}
