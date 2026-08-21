<?php

namespace VelaBuild\Core\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Make a copied accordion open and close again.
 *
 * The importer strips every script on purpose — running another site's
 * JavaScript here is not a trade worth making — and an accordion is all
 * script. What arrives is the markup with its panels hidden the way the
 * source left them, so the questions are visible, the answers are not, and
 * clicking does nothing at all.
 *
 * The pairs are found at render time and marked for the small toggle in the
 * page footer. Doing it on the way out rather than on the way in means
 * sections imported before this existed work too, and the stored markup stays
 * exactly as it was copied.
 */
class ImportedDisclosures
{
    /**
     * Make a copied accordion open and close again.
     *
     * The importer strips every script on purpose — running another site's
     * JavaScript here is not a trade worth making — and an accordion is all
     * script. What arrives is the markup with its panels already hidden the
     * way the source left them, so the questions are visible, the answers are
     * not, and clicking does nothing at all.
     *
     * The pairs are found here, at render time, and marked for the small
     * toggle in the page footer. Doing it on the way out rather than on the
     * way in means sections imported before this existed work too, and the
     * stored markup stays exactly as it was copied.
     */
    public static function wire(string $html): string
    {
        if ($html === '' || !preg_match('/display\s*:\s*none|aria-controls|hidden/i', $html)) {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $loaded = $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="vela-disclosure-root">' . $html . '</div>',
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        $xpath = new DOMXPath($doc);
        $wired = [];

        // A source that shipped the accessibility attributes says outright
        // which element controls which panel; nothing has to be guessed.
        foreach ($xpath->query('//*[@aria-controls]') ?: [] as $trigger) {
            if (!$trigger instanceof DOMElement) {
                continue;
            }

            $panel = $doc->getElementById(trim($trigger->getAttribute('aria-controls')));
            if ($panel instanceof DOMElement && self::pairIsSane($trigger, $panel)) {
                $expanded = strtolower($trigger->getAttribute('aria-expanded')) === 'true';
                self::wirePair($doc, $trigger, $panel, $expanded, $wired);
            }
        }

        // Otherwise: a hidden block of prose with a heading in front of it is
        // an answer with its question, whatever the site called the classes.
        foreach ($xpath->query('//*[contains(translate(@style, "DISPLAYNONE", "displaynone"), "display:none") or contains(translate(@style, "DISPLAYNONE", "displaynone"), "display: none")]') ?: [] as $panel) {
            if (!$panel instanceof DOMElement || isset($wired[$panel->getNodePath()])) {
                continue;
            }

            $trigger = self::triggerFor($panel);
            if ($trigger !== null && self::pairIsSane($trigger, $panel)) {
                self::wirePair($doc, $trigger, $panel, false, $wired);
            }
        }

        if (!$wired) {
            return $html;
        }

        $root = $doc->getElementById('vela-disclosure-root');

        return $root ? vela_inner_html($root) : $html;
    }
    /**
     * The thing a visitor would click to reveal this panel.
     *
     * Preference goes to the whole row over the line of text inside it: a
     * copied accordion usually puts a chevron next to the question, and a row
     * that only responds on the words reads as broken to anyone who aims for
     * the arrow.
     */
    private static function triggerFor(DOMElement $panel): ?DOMElement
    {
        $label = self::previousElement($panel);

        if ($label === null) {
            $parent = $panel->parentNode;
            $label = $parent instanceof DOMElement ? self::previousElement($parent) : null;
        }

        if ($label === null || mb_strlen(trim($label->textContent)) < 3) {
            return null;
        }

        // Walk out while the ancestor still holds this panel and nothing else
        // that looks like a second question.
        $trigger = $label;
        $ancestor = $label->parentNode;
        while ($ancestor instanceof DOMElement && $ancestor->getAttribute('id') !== 'vela-disclosure-root') {
            if (!self::contains($ancestor, $panel)) {
                break;
            }
            $trigger = $ancestor;
            if (self::looksLikeRow($ancestor)) {
                break;
            }
            $ancestor = $ancestor->parentNode;
        }

        return $trigger === $panel ? null : $trigger;
    }
    /**
     * Reject the hidden things that are not accordions.
     *
     * Copied markup is full of elements that start hidden and are none of our
     * business: modals, cookie banners, mobile menus, form honeypots. Opening
     * those on a click would be worse than leaving them alone.
     */
    private static function pairIsSane(DOMElement $trigger, DOMElement $panel): bool
    {
        if ($trigger === $panel || self::contains($panel, $trigger)) {
            return false;
        }

        if (in_array(strtolower($panel->tagName), ['script', 'style', 'input', 'template'], true)) {
            return false;
        }

        // A <details> already opens by itself.
        foreach ([$trigger, $panel] as $element) {
            $node = $element;
            while ($node instanceof DOMElement) {
                if (strtolower($node->tagName) === 'details') {
                    return false;
                }
                $node = $node->parentNode;
            }
        }

        $signature = strtolower(
            $panel->getAttribute('class') . ' ' . $panel->getAttribute('id') . ' ' .
            ($panel->parentNode instanceof DOMElement ? $panel->parentNode->getAttribute('class') : '')
        );

        foreach (['modal', 'popup', 'dialog', 'dropdown', 'menu', 'nav', 'tooltip', 'toast', 'cookie', 'consent', 'overlay', 'drawer', 'lightbox', 'honeypot'] as $word) {
            if (str_contains($signature, $word)) {
                return false;
            }
        }

        // An answer is prose. A hidden wrapper holding a form or an image and
        // no words is something else.
        return mb_strlen(trim($panel->textContent)) >= 20;
    }
    /**
     * Mark one question/answer pair for the footer's toggle.
     *
     * @param  array<string, true>  $wired
     */
    private static function wirePair(DOMDocument $doc, DOMElement $trigger, DOMElement $panel, bool $expanded, array &$wired): void
    {
        // Keyed by node path, not by object: PHP hands out a new DOMElement
        // wrapper on every access and recycles the object ids behind them, so
        // an id-keyed set silently reports rows as already wired.
        $panelKey = $panel->getNodePath();
        $triggerKey = $trigger->getNodePath();

        if (isset($wired[$panelKey]) || isset($wired[$triggerKey])) {
            return;
        }

        $id = trim($panel->getAttribute('id'));
        if ($id === '') {
            $id = 'vela-disclosure-' . substr(md5($panelKey), 0, 8);
            $panel->setAttribute('id', $id);
        }

        $panel->setAttribute('data-vela-disclosure-panel', '');
        $panel->setAttribute('data-state', $expanded ? 'open' : 'closed');
        if (!$expanded) {
            // The source may have shown it with a class this site never got;
            // the state has to be unambiguous before the first click.
            $panel->setAttribute('style', self::styleWithoutDisplay($panel->getAttribute('style')) . 'display:none;');
        }

        $trigger->setAttribute('data-vela-disclosure', $id);
        $trigger->setAttribute('aria-controls', $id);
        $trigger->setAttribute('aria-expanded', $expanded ? 'true' : 'false');
        $trigger->setAttribute('data-state', $expanded ? 'open' : 'closed');

        $tag = strtolower($trigger->tagName);
        if (!in_array($tag, ['button', 'summary', 'a'], true)) {
            // Keyboard users get to it, and it announces itself as a control.
            if (trim($trigger->getAttribute('role')) === '') {
                $trigger->setAttribute('role', 'button');
            }
            if (trim($trigger->getAttribute('tabindex')) === '') {
                $trigger->setAttribute('tabindex', '0');
            }
        }

        $style = trim($trigger->getAttribute('style'));
        if (!str_contains(strtolower($style), 'cursor')) {
            $trigger->setAttribute('style', rtrim($style, '; ') . ($style === '' ? '' : ';') . 'cursor:pointer;');
        }

        $wired[$panelKey] = true;
        $wired[$triggerKey] = true;
    }
    /** The inline style with any display declaration removed. */
    private static function styleWithoutDisplay(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);
            if ($declaration === '' || preg_match('/^display\s*:/i', $declaration)) {
                continue;
            }
            $kept[] = $declaration;
        }

        return $kept ? implode(';', $kept) . ';' : '';
    }
    /** The element before this one, skipping whitespace and comments. */
    private static function previousElement(DOMElement $element): ?DOMElement
    {
        $node = $element->previousSibling;
        while ($node !== null && !$node instanceof DOMElement) {
            $node = $node->previousSibling;
        }

        return $node instanceof DOMElement ? $node : null;
    }
    /** Whether this element is the accordion's own row wrapper. */
    private static function looksLikeRow(DOMElement $element): bool
    {
        $signature = strtolower($element->getAttribute('class') . ' ' . $element->getAttribute('id'));

        foreach (['accordion', 'faq', 'question', 'collaps', 'toggle', 'disclosure', 'row', 'item'] as $word) {
            if (str_contains($signature, $word)) {
                return true;
            }
        }

        return false;
    }
    /** Whether the needle sits inside the haystack element. */
    private static function contains(DOMElement $haystack, DOMElement $needle): bool
    {
        $node = $needle->parentNode;
        while ($node instanceof DOMElement) {
            if ($node === $haystack) {
                return true;
            }
            $node = $node->parentNode;
        }

        return false;
    }
}
