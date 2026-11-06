<?php

namespace VelaBuild\Core\Services\Translation;

use VelaBuild\Core\Contracts\AiTextProvider;

/**
 * Translates the prose inside page blocks (content + settings JSON).
 *
 * Two passes over the same walker: the first collects every translatable
 * string (plain fields, and the prose segments of HTML fields); those are
 * translated in batches as JSON arrays — a handful of AI calls per page
 * instead of one per string — and the second pass writes them back. URLs,
 * class names, code, numbers and markup never reach the model; a returned
 * segment whose tags differ from the source is rejected and the source text
 * kept.
 */
class PageBodyTranslator
{
    /** Keys whose values are never prose. Matched against each `_`/`-` separated part. */
    private const SKIP_KEY = '/(?:^|[_-])(url|urls|href|link|links|src|srcset|image|images|img|icon|icons|class|classes|css|style|styles|id|ids|color|colour|bg|background|type|slug|email|video|width|height|align|alignment|target|anchor|variant|layout|code|script|js|json|language|lang|locale|key|font|size|columns|order|position|theme|animation|embed|svg|rel|loading|format|mode|ratio|path|file|mime|tag|tags|method|action|field|interval|currency|amount|price_id|plan|plan_key|provider|model|endpoint|selector|attr|attributes)$/i';

    /** Keys whose value is always visible text. */
    private const TEXT_KEY = '/^(period|badge|label|title|subtitle|heading|eyebrow|text|description|caption|price_note|period_yearly|price_note_yearly|cta_text|button_text|features_cap)$/';

    /** Block types whose content is code or media, not prose. */
    private const SKIP_TYPES = ['code', 'embed', 'script', 'custom_code', 'raw_code', 'iframe', 'spacer', 'divider', 'map', 'video'];

    /** Source characters per AI call. */
    private const BATCH_CHARS = 6000;

    /** @var array<string, array<string, string>> locale → source → translation, for the life of this object */
    private array $memo = [];

    public int $calls = 0;
    public int $failed = 0;
    /** Distinct strings asked for, and how many came back usable. */
    public int $total = 0;
    public int $succeeded = 0;

    public function __construct(private AiTextProvider $provider, private string $localeName) {}

    /**
     * @param  list<array{type: ?string, content: mixed, settings: mixed}>  $blocks
     * @return list<array{content: mixed, settings: mixed}>  same order
     */
    public function translateBlocks(array $blocks, string $locale): array
    {
        $strings = [];
        foreach ($blocks as $b) {
            if (in_array($b['type'] ?? '', self::SKIP_TYPES, true)) {
                continue;
            }
            $this->walk($b['content'], null, function (string $s) use (&$strings) { $strings[$s] = true; });
            $this->walk($b['settings'], null, function (string $s) use (&$strings) { $strings[$s] = true; });
        }

        $map = $this->translateAll(array_keys($strings), $locale);
        $this->total += count($strings);
        $this->succeeded += count($map);

        $out = [];
        foreach ($blocks as $b) {
            if (in_array($b['type'] ?? '', self::SKIP_TYPES, true)) {
                $out[] = ['content' => $b['content'], 'settings' => $b['settings']];
                continue;
            }
            $out[] = [
                'content'  => $this->walk($b['content'], null, null, $map),
                'settings' => $this->walk($b['settings'], null, null, $map),
            ];
        }

        return $out;
    }

    /**
     * Collect ($collect) or replace ($map) translatable strings in a JSON value.
     * HTML strings contribute their segments, not the whole string.
     */
    private function walk(mixed $value, ?string $key, ?callable $collect, ?array $map = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k) && preg_match(self::SKIP_KEY, $k)) {
                    continue;
                }
                $value[$k] = $this->walk($v, is_string($k) ? $k : $key, $collect, $map);
            }

            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        if (HtmlSegments::looksLikeHtml($value)) {
            if ($collect) {
                foreach (HtmlSegments::find($value) as $seg) {
                    $collect($seg['text']);
                }

                return $value;
            }

            return HtmlSegments::apply($value, $map ?? []);
        }

        if (!self::isProse($value, $key)) {
            return $value;
        }
        if ($collect) {
            $collect(trim($value));

            return $value;
        }
        $t = $map[trim($value)] ?? null;

        return $t === null ? $value : str_replace(trim($value), $t, $value);
    }

    /**
     * Plain (non-HTML) string worth translating. Under a display-text key
     * ("period", "badge", …) a single lowercase word is a word, not an option
     * value: "forever" under a $0 price.
     */
    public static function isProse(string $value, ?string $key = null): bool
    {
        $v = trim($value);
        if ($v === '' || !preg_match('/\p{L}{2,}/u', $v)) {
            return false;
        }
        if ($key !== null && preg_match(self::TEXT_KEY, $key) && preg_match('/^\p{Ll}{2,}$/u', $v)) {
            return true;
        }
        if (preg_match('~^(https?:|mailto:|tel:|www\.)~i', $v) || preg_match('~^[/#.]\S*$~', $v) || filter_var($v, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        // Identifiers and option values: "email", "primary", "pricing_tier", "btn ghost".
        if (preg_match('/^[a-z0-9]+(?:[_-][a-z0-9]+)*$/', $v)) {
            return false;
        }
        if (preg_match('/^[a-z0-9_-]+(?:\s+[a-z0-9_-]+)*$/', $v) && preg_match('/[_-]/', $v)) {
            return false;
        }
        // CSS / code-ish.
        if (preg_match('/[{};]\s*$|^\s*[.#]?[a-z-]+\s*\{|\$[a-z_]+|=>|::|\(\)/i', $v)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $sources
     * @return array<string, string>  source → translation (failures omitted)
     */
    public function translateAll(array $sources, string $locale): array
    {
        $memo = $this->memo[$locale] ?? [];
        $todo = array_values(array_filter($sources, fn ($s) => !isset($memo[$s])));

        $batch = [];
        $size = 0;
        foreach ($todo as $s) {
            if ($batch !== [] && $size + strlen($s) > self::BATCH_CHARS) {
                $memo += $this->translateBatch($batch, $locale);
                $batch = [];
                $size = 0;
            }
            $batch[] = $s;
            $size += strlen($s);
        }
        if ($batch !== []) {
            $memo += $this->translateBatch($batch, $locale);
        }
        $this->memo[$locale] = $memo;

        return array_intersect_key($memo, array_flip($sources));
    }

    /** @return array<string, string> */
    private function translateBatch(array $batch, string $locale): array
    {
        $protected = [];
        $send = [];
        foreach ($batch as $i => $s) {
            [$send[$i], $protected[$i]] = HtmlSegments::protect($s);
        }

        $result = $this->ask($send);
        if ($result === null) {
            if (count($batch) > 1) {
                $half = (int) ceil(count($batch) / 2);

                return $this->translateBatch(array_slice($batch, 0, $half), $locale)
                    + $this->translateBatch(array_slice($batch, $half), $locale);
            }
            $this->failed++;

            return [];
        }

        $out = [];
        foreach ($batch as $i => $source) {
            $t = is_string($result[$i] ?? null) ? trim($result[$i]) : '';
            $t = $t === '' ? null : HtmlSegments::restore($t, $protected[$i]);
            if ($t === null || !HtmlSegments::sameMarkup($source, $t)) {
                $this->failed++;
                continue; // keep the source text for this one
            }
            $out[$source] = $t;
        }

        return $out;
    }

    /** One AI call: JSON array in, JSON array of the same length out, or null. */
    private function ask(array $strings): ?array
    {
        $json = json_encode(array_values($strings), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $prompt = <<<P
Translate each string in this JSON array from English into {$this->localeName}. They are the text of one web page, in order, so keep terminology consistent.

Rules:
  - Return ONLY a JSON array of strings, the same length and order as the input. No commentary, no code fences.
  - Keep every HTML tag and attribute exactly as written (same tags, same attributes, same URLs); translate only the words between them.
  - Keep tokens like ⟦0⟧ exactly as they are.
  - Do not translate: product and brand names (Vela, Vela CMS, Laravel, PHP, Stripe, GitHub, Cloudflare, OpenAI, Claude, Gemini, MIT), the plan names Starter, Pro and Concierge, code, commands, file names, URLs, email addresses, prices and numbers.
  - Write clear, natural, plain {$this->localeName} for the reader of a modern software website, not a word-for-word rendering.
  - Do not use the em dash (—). Use a comma, colon or full stop instead, unless {$this->localeName} grammar requires a dash.

Input:
{$json}
P;

        $this->calls++;
        try {
            $raw = $this->provider->generateText($prompt, 16000, 0.2);
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($raw)) {
            return null;
        }
        $start = strpos($raw, '[');
        $end = strrpos($raw, ']');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (!is_array($decoded) || count($decoded) !== count($strings)) {
            return null;
        }

        return array_values($decoded);
    }
}
