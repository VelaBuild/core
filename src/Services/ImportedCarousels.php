<?php

namespace VelaBuild\Core\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Make a copied carousel move again.
 *
 * Same story as the accordions: the slides, the arrows and the dots all come
 * across, and the script that moved them does not. What is left is the first
 * slide, two arrows that do nothing, and — because the mask hides the
 * overflow — no way to reach the rest of the content at all.
 *
 * The track is turned into a scroll-snapping strip at render time, which is
 * a carousel a browser can drive on its own: it swipes on a phone, scrolls on
 * a trackpad, and the copied arrows and dots are wired to it.
 */
class ImportedCarousels
{
    /** Class fragments that name the moving strip in the common exports. */
    private const TRACK_HINTS = [
        'w-slider-mask',   // Webflow
        'swiper-wrapper',  // Swiper
        'slick-track',     // Slick
        'splide__list',    // Splide
        'glide__slides',   // Glide
        'embla__container',// Embla
        'carousel-inner',  // Bootstrap
    ];

    /** Class names that name one slide, matched whole rather than in part. */
    private const SLIDE_CLASSES = [
        'w-slide',
        'swiper-slide',
        'slick-slide',
        'splide__slide',
        'glide__slide',
        'embla__slide',
        'carousel-item',
        'slide',
        'item',
    ];

    public static function wire(string $html): string
    {
        if ($html === '' || !preg_match('/slider|slide|swiper|slick|splide|glide|embla|carousel/i', $html)) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="vela-carousel-root">' . $html . '</div>',
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $xpath = new DOMXPath($doc);
        $wired = 0;

        foreach ($xpath->query('//*') ?: [] as $track) {
            if (!$track instanceof DOMElement) {
                continue;
            }

            $slides = self::slidesOf($track);
            if (count($slides) < 2) {
                continue;
            }

            // The outermost track wins: a nested strip inside one already
            // wired would fight the parent for the same drag.
            if (self::insideWiredTrack($track)) {
                continue;
            }

            $wired++;
            $id = 'vela-carousel-' . substr(md5($track->getNodePath()), 0, 8);

            $track->setAttribute('data-vela-carousel-track', $id);
            foreach ($slides as $index => $slide) {
                $slide->setAttribute('data-vela-slide', (string) $index);
            }

            self::wireControls($doc, $track, $id, count($slides));
        }

        if ($wired === 0) {
            return $html;
        }

        $root = $doc->getElementById('vela-carousel-root');

        return $root ? vela_inner_html($root) : $html;
    }

    /**
     * The element's children that are slides — all of them, or none.
     *
     * A strip is only a carousel when its children are siblings of the same
     * kind. Requiring that keeps ordinary grids and lists of cards, which
     * often carry a class with "slide" somewhere in it, out of this.
     *
     * @return array<int, DOMElement>
     */
    private static function slidesOf(DOMElement $track): array
    {
        $children = [];
        foreach ($track->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        if (count($children) < 2) {
            return [];
        }

        $named = self::hasTrackHint($track);
        $slides = 0;

        foreach ($children as $child) {
            if (self::isSlide($child)) {
                $slides++;
                continue;
            }

            // Inside a track the export named, unlabelled children are still
            // its slides. Outside one, every child has to say so itself.
            if (!$named) {
                return [];
            }
        }

        if ($slides === 0) {
            return [];
        }

        return $named || $slides === count($children) ? $children : [];
    }

    /** Whether the element is the moving strip of a known export. */
    private static function hasTrackHint(DOMElement $element): bool
    {
        $classes = strtolower($element->getAttribute('class'));

        foreach (self::TRACK_HINTS as $hint) {
            if (str_contains($classes, $hint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this element is one slide.
     *
     * Matched on whole class names, not substrings: "w-slider-mask",
     * "w-slider-arrow-left" and "w-slider-nav" all contain "slide", and
     * reading them as slides turns the slider's own chrome into its content.
     */
    private static function isSlide(DOMElement $element): bool
    {
        foreach (preg_split('/\s+/', strtolower(trim($element->getAttribute('class')))) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            if (in_array($token, self::SLIDE_CLASSES, true) || preg_match('/(^|[-_])slide$/', $token)) {
                return true;
            }
        }

        return false;
    }

    private static function insideWiredTrack(DOMElement $element): bool
    {
        $node = $element->parentNode;
        while ($node instanceof DOMElement) {
            if ($node->hasAttribute('data-vela-carousel-track')) {
                return true;
            }
            $node = $node->parentNode;
        }

        return false;
    }

    /**
     * Point the copied arrows and dots at this track.
     *
     * They are looked for in the carousel's own container rather than the
     * whole section, so a page with two sliders does not wire one's arrows to
     * the other's slides.
     */
    private static function wireControls(DOMDocument $doc, DOMElement $track, string $id, int $slideCount): void
    {
        $root = $track->parentNode;
        if (!$root instanceof DOMElement) {
            return;
        }

        $xpath = new DOMXPath($doc);

        foreach ([
            'prev' => ['prev', 'arrow-left', 'arrow_left', 'left-arrow', 'previous', 'back'],
            'next' => ['next', 'arrow-right', 'arrow_right', 'right-arrow', 'forward'],
        ] as $direction => $hints) {
            foreach ($xpath->query('.//*[@class or @aria-label]', $root) ?: [] as $candidate) {
                if (!$candidate instanceof DOMElement || $candidate->hasAttribute('data-vela-carousel-prev') || $candidate->hasAttribute('data-vela-carousel-next')) {
                    continue;
                }

                $signature = strtolower($candidate->getAttribute('class') . ' ' . $candidate->getAttribute('aria-label'));
                $isControl = false;
                foreach ($hints as $hint) {
                    if (str_contains($signature, $hint)) {
                        $isControl = true;
                        break;
                    }
                }

                // The chevron glyph inside the arrow carries the same words;
                // the clickable thing is the outer one.
                if (!$isControl || self::insideTrack($candidate, $track) || self::hasControlAncestor($candidate, $root)) {
                    continue;
                }

                $candidate->setAttribute('data-vela-carousel-' . $direction, $id);
                if (trim($candidate->getAttribute('role')) === '' && strtolower($candidate->tagName) !== 'button') {
                    $candidate->setAttribute('role', 'button');
                    $candidate->setAttribute('tabindex', '0');
                }
                if (trim($candidate->getAttribute('aria-label')) === '') {
                    $candidate->setAttribute('aria-label', $direction === 'prev' ? 'Previous slide' : 'Next slide');
                }
                break;
            }
        }

        // Webflow and friends build their dots in script, so the nav element
        // arrives empty. Filling it is the difference between a strip with no
        // position indicator and one that reads as a carousel.
        foreach ($xpath->query('.//*[contains(concat(" ", translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " "), "nav") or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "pagination") or contains(translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "dots")]', $root) ?: [] as $nav) {
            if (!$nav instanceof DOMElement || self::insideTrack($nav, $track) || trim($nav->textContent) !== '') {
                continue;
            }

            $nav->setAttribute('data-vela-carousel-dots', $id);
            for ($i = 0; $i < $slideCount; $i++) {
                $dot = $doc->createElement('button');
                $dot->setAttribute('type', 'button');
                $dot->setAttribute('data-vela-carousel-dot', (string) $i);
                $dot->setAttribute('aria-label', 'Go to slide ' . ($i + 1));
                $nav->appendChild($dot);
            }
            break;
        }
    }

    private static function insideTrack(DOMElement $element, DOMElement $track): bool
    {
        $node = $element;
        while ($node instanceof DOMElement) {
            if ($node === $track) {
                return true;
            }
            $node = $node->parentNode;
        }

        return false;
    }

    private static function hasControlAncestor(DOMElement $element, DOMElement $stopAt): bool
    {
        $node = $element->parentNode;
        while ($node instanceof DOMElement && $node !== $stopAt) {
            if ($node->hasAttribute('data-vela-carousel-prev') || $node->hasAttribute('data-vela-carousel-next')) {
                return true;
            }
            $node = $node->parentNode;
        }

        return false;
    }
}
