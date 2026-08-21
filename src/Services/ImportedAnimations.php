<?php

namespace VelaBuild\Core\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Show the parts of a copied section that were waiting for an animation.
 *
 * Builders like Webflow write the *starting* frame of a scroll animation into
 * the markup — opacity:0, a transform that holds the element 130px below where
 * it belongs — and let their script animate it to the finished state when the
 * element scrolls into view. That script is stripped on import, so the start
 * frame is the only frame: headings, logos and whole cards are in the page,
 * invisible, and the section reads as though half of it failed to copy.
 *
 * Clearing those declarations leaves the element where the animation would
 * have put it. Nothing is added to the page — only a first frame that can
 * never advance is taken away.
 */
class ImportedAnimations
{
    public static function settle(string $html): string
    {
        if ($html === '' || !preg_match('/opacity\s*:\s*0|transform\s*:/i', $html)) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="vela-animation-root">' . $html . '</div>',
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $xpath = new DOMXPath($doc);
        $changed = 0;

        foreach ($xpath->query('//*[@style]') ?: [] as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            $style = $element->getAttribute('style');
            $settled = self::settleStyle($style);

            if ($settled !== $style) {
                $changed++;
                if (trim($settled) === '') {
                    $element->removeAttribute('style');
                } else {
                    $element->setAttribute('style', $settled);
                }
            }
        }

        if ($changed === 0) {
            return $html;
        }

        $root = $doc->getElementById('vela-animation-root');

        return $root ? vela_inner_html($root) : $html;
    }

    /**
     * The same inline style with an unplayable opening frame removed.
     */
    private static function settleStyle(string $style): string
    {
        $declarations = [];
        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration !== '') {
                $declarations[] = $declaration;
            }
        }

        if (!$declarations) {
            return $style;
        }

        // A transform is only dropped when the element was clearly staged by a
        // builder: the vendor-prefixed set is the signature. A lone
        // `transform: rotate(-3deg)` is somebody's design and stays.
        $prefixed = 0;
        foreach ($declarations as $declaration) {
            if (preg_match('/^-(webkit|moz|ms)-transform\s*:/i', $declaration)) {
                $prefixed++;
            }
        }
        $dropTransforms = $prefixed >= 2;

        $kept = [];
        foreach ($declarations as $declaration) {
            if (preg_match('/^opacity\s*:\s*0(\.0+)?$/i', $declaration)) {
                continue;
            }

            if ($dropTransforms && preg_match('/^(-(webkit|moz|ms)-)?transform\s*:/i', $declaration)) {
                continue;
            }

            $kept[] = $declaration;
        }

        // Nothing dropped means nothing to rewrite: reserialising every style
        // on the page would churn markup this had no business touching.
        if (count($kept) === count($declarations)) {
            return $style;
        }

        return $kept ? implode(';', $kept) . ';' : '';
    }
}
