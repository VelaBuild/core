<?php

namespace VelaBuild\Core\Services\Translation;

/**
 * Finds the prose in an HTML string so it can be translated without the
 * markup ever passing through a model.
 *
 * The HTML is split into tokens (tags, text, and "protected" regions such as
 * <script>, <style>, <pre>, <code> and <svg>). Runs of text and inline tags
 * between block-level boundaries form one segment — a heading, a paragraph,
 * a list item, a button label — so a sentence with a <strong> in it is
 * translated as a sentence, not as fragments. Everything outside the
 * segments is left byte-for-byte as it was.
 *
 * Anything inside an element marked translate="no", or whose class looks like
 * code (src, code, terminal, mono, …), is never offered for translation.
 */
class HtmlSegments
{
    /** Elements that stay inside a segment rather than ending it. */
    private const INLINE = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'data', 'dfn', 'em', 'i', 'img', 'kbd', 'mark',
        'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr', 'del', 'ins',
    ];

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr', 'param'];

    /** Regions copied verbatim; inside a segment they become placeholders. */
    private const PROTECTED = 'script|style|pre|code|svg|textarea|template|math|kbd|samp';

    private const CODE_CLASS = '/(?:^|\s)(?:code|src|source|terminal|term|shell|cmd|prompt|console|mono|monospace|hljs|highlight|ln|line-numbers?|token|no-?translate|notranslate)(?:\s|$|-)/i';

    /**
     * @return list<array{offset: int, length: int, text: string}>  segments worth translating, in document order
     */
    public static function find(string $html): array
    {
        $re = '~<!--.*?-->|<(' . self::PROTECTED . ')\b[^>]*>.*?</\1\s*>|<[^>]+>|[^<]+|<~is';
        preg_match_all($re, $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $segments = [];
        $stack = [];          // [name, skip]
        $current = null;      // [start, end]

        $skip = static function () use (&$stack): bool {
            return $stack !== [] && end($stack)[1];
        };
        $flush = static function () use (&$current, &$segments, $html, &$stack): void {
            if ($current === null) {
                return;
            }
            [$start, $end] = $current;
            $current = null;
            $skipped = $stack !== [] && end($stack)[1];
            if ($skipped) {
                return;
            }
            $raw = substr($html, $start, $end - $start);
            $lead = strlen($raw) - strlen(ltrim($raw));
            $text = trim($raw);
            if ($text === '' || !self::hasProse($text)) {
                return;
            }
            $segments[] = ['offset' => $start + $lead, 'length' => strlen($text), 'text' => $text];
        };

        foreach ($m as $tok) {
            [$token, $offset] = $tok[0];
            $end = $offset + strlen($token);

            if (str_starts_with($token, '<!--')) {
                if ($current !== null) {
                    $current[1] = $end;
                }
                continue;
            }

            if (isset($tok[1]) && $tok[1][0] !== '') {
                // Protected region: inline code stays in the sentence as a placeholder,
                // anything block-like (pre, script, style, svg …) ends the segment.
                $name = strtolower($tok[1][0]);
                if (in_array($name, ['code', 'kbd', 'samp'], true) && $current !== null) {
                    $current[1] = $end;
                } else {
                    $flush();
                }
                continue;
            }

            if ($token[0] === '<' && strlen($token) > 1 && preg_match('~^<(/?)([a-zA-Z][a-zA-Z0-9-]*)~', $token, $t)) {
                $closing = $t[1] === '/';
                $name = strtolower($t[2]);

                if (in_array($name, self::INLINE, true)) {
                    if ($current === null) {
                        $current = [$offset, $end];
                    } else {
                        $current[1] = $end;
                    }
                    continue;
                }

                $flush();
                if ($closing) {
                    for ($i = count($stack) - 1; $i >= 0; $i--) {
                        if ($stack[$i][0] === $name) {
                            array_splice($stack, $i);
                            break;
                        }
                    }
                } elseif (!in_array($name, self::VOID, true) && !str_ends_with($token, '/>')) {
                    $parentSkip = $skip();
                    $stack[] = [$name, $parentSkip || self::isCodeLike($token)];
                }
                continue;
            }

            // Text (or a stray "<").
            if ($current === null) {
                $current = [$offset, $end];
            } else {
                $current[1] = $end;
            }
        }
        $flush();

        return $segments;
    }

    /** Put translated segments back; $map is segment text → translation. */
    public static function apply(string $html, array $map): string
    {
        $segments = self::find($html);
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            $seg = $segments[$i];
            if (!isset($map[$seg['text']])) {
                continue;
            }
            $html = substr_replace($html, $map[$seg['text']], $seg['offset'], $seg['length']);
        }

        return $html;
    }

    public static function looksLikeHtml(string $value): bool
    {
        return (bool) preg_match('~<(?:[a-zA-Z][a-zA-Z0-9-]*)(?:\s[^>]*)?/?>~', $value);
    }

    /**
     * Swap inline code and comments for ⟦n⟧ tokens before a segment goes to
     * the model; restore() puts them back.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function protect(string $segment): array
    {
        $kept = [];
        $out = preg_replace_callback('~<!--.*?-->|<(code|kbd|samp)\b[^>]*>.*?</\1\s*>~is', function ($m) use (&$kept) {
            $kept[] = $m[0];

            return '⟦' . (count($kept) - 1) . '⟧';
        }, $segment);

        return [$out, $kept];
    }

    public static function restore(string $translated, array $kept): ?string
    {
        foreach ($kept as $i => $original) {
            $token = '⟦' . $i . '⟧';
            if (substr_count($translated, $token) !== 1) {
                return null;
            }
            $translated = str_replace($token, $original, $translated);
        }

        return $translated;
    }

    /** Same tags (exact text, any order) in both: the model changed words only. */
    public static function sameMarkup(string $a, string $b): bool
    {
        preg_match_all('~<[^>]+>~', $a, $x);
        preg_match_all('~<[^>]+>~', $b, $y);
        $x = $x[0];
        $y = $y[0];
        sort($x);
        sort($y);

        return $x === $y;
    }

    public static function hasProse(string $text): bool
    {
        $plain = html_entity_decode(strip_tags(preg_replace('~<(code|kbd|samp)\b[^>]*>.*?</\1\s*>~is', '', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim($plain);

        return $plain !== '' && preg_match('/\p{L}{2,}/u', $plain) === 1;
    }

    private static function isCodeLike(string $openTag): bool
    {
        if (preg_match('~\btranslate\s*=\s*["\']?no\b~i', $openTag) || preg_match('~\bdata-no-translate\b~i', $openTag)) {
            return true;
        }
        if (preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~is', $openTag, $c)) {
            return (bool) preg_match(self::CODE_CLASS, $c[2]);
        }

        return false;
    }
}
