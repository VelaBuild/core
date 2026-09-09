<?php

namespace VelaBuild\Core\Services;

/**
 * Words on the page that do not look like words.
 *
 * A build reads its wording off a picture, and a picture can be read wrong.
 * Runs have put "Searee" and "Wluctn znoe." onto a page and reported success:
 * every guard passed, because nothing anywhere asked whether what was read is
 * language at all. The QA rounds cannot catch it either — they compare a
 * photograph of the page with the design, and a misread word is the same shape
 * and in the same place as the right one.
 *
 * This is the check that needs no AI and no dictionary: a word made of Latin
 * letters that no language spells that way — no vowel in it, an opening
 * consonant cluster English and its loanwords do not have, a run of five
 * consonants, the same letter three times. Those are facts about the letters,
 * so they hold for a brand name nobody has heard of as much as for a common
 * noun.
 *
 * What it deliberately cannot do is tell a real unfamiliar name from a misread
 * one — "Searee" passes every rule here, and only the design itself says
 * whether that is what the logo reads. So the finding is REPORTED, never
 * refused: told its wording is wrong, a model rewrites it, and a fix round
 * inventing fresh marketing copy over sentences that were read correctly is a
 * failure this feature has already had once (the fix loop used to be sent text
 * with no design attached, and did exactly that).
 */
class MisreadWords
{
    /** Beyond this many, the answer is "the page is gibberish", not a list. */
    private const MOST_TO_REPORT = 12;

    /** y counts: a word with no a-e-i-o-u-y in it is not being pronounced. */
    private const VOWELS = 'aeiouy';

    /**
     * Consonant clusters a word can begin with.
     *
     * English's own, plus the ones that arrive with borrowed and invented
     * names — a site is as likely to be called Zloty or Fjord or Tsuki as
     * anything in a dictionary. Anything not here is a shape no language
     * hands to a word's first syllable: "wl", "zn", "ctn".
     *
     * @var array<int, string>
     */
    private const ONSETS = [
        'bh', 'bl', 'br', 'ch', 'chl', 'chr', 'chth', 'cl', 'cr', 'cz', 'dh', 'dj', 'dr', 'dw', 'dz',
        'fj', 'fl', 'fr', 'gh', 'gj', 'gl', 'gn', 'gr', 'gw', 'hj', 'hr', 'hv', 'kh', 'kl',
        'kn', 'kr', 'kv', 'kw', 'kj', 'll', 'mc', 'mn', 'ng', 'nh', 'nj', 'ph', 'phl', 'phr', 'pf', 'phth', 'pl',
        'pn', 'pr', 'ps', 'pt', 'qu', 'rh', 'sc', 'sch', 'schl', 'schm', 'schn', 'schr', 'schw', 'scl', 'scr', 'sh', 'shl', 'shm',
        'shn', 'shr', 'shv', 'shw', 'sj', 'sk', 'skr', 'skw', 'sl', 'sm', 'sn', 'sp', 'sph',
        'spl', 'spr', 'sq', 'squ', 'sr', 'st', 'str', 'sv', 'sw', 'sz', 'th', 'thr', 'thw',
        'tch', 'tj', 'tr', 'ts', 'tsch', 'tsw', 'tw', 'tz', 'vl', 'vr', 'wh', 'wr', 'xh', 'zh', 'zl', 'zw',
    ];

    /**
     * Argument names that hold something other than wording.
     *
     * @var array<int, string>
     */
    private const NOT_WORDING = [
        'block_type', 'class', 'code', 'colour', 'color', 'command', 'css', 'custom_css', 'file',
        'filename', 'font', 'format', 'href', 'icon', 'id', 'image', 'image_url', 'key', 'link',
        'page_id', 'page_slug', 'path', 'provider', 'query', 'row_id', 'scope', 'selector', 'slug',
        'sql', 'src', 'style', 'template', 'theme', 'type', 'url',
    ];

    /**
     * The words in a piece of writing that no language spells that way.
     *
     * @param  array<int, string> $known wording that is known to be right — the
     *         brief, the site's name, what is already on the site. A name the
     *         person asking for the build wrote down themselves is not a
     *         misreading of anything.
     * @return array<int, string>
     */
    public static function in(string $text, array $known = []): array
    {
        $knownWords = self::wordsOf(implode(' ', $known));
        $found = [];

        foreach (self::tokens($text) as $word) {
            $plain = mb_strtolower($word);

            if (isset($knownWords[$plain]) || isset($found[$plain])) {
                continue;
            }

            if (self::looksLikeAWord($word)) {
                continue;
            }

            $found[$plain] = $word;
        }

        return array_slice(array_values($found), 0, self::MOST_TO_REPORT);
    }

    /**
     * The words a reader would see, with the markup and the class names gone.
     *
     * Only the wording is examined, never the markup: class names are written
     * short on purpose ("btn", "hdr", "wrap"), and every one of them would be
     * reported here as a word that is not a word.
     */
    public static function visibleText(string $html): string
    {
        $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        // Attributes carry addresses, class names and code; alt text is the
        // one that is read aloud, and it is wording like any other.
        preg_match_all('/\balt\s*=\s*"([^"]*)"|\balt\s*=\s*\'([^\']*)\'/i', $text, $alts);
        $text = preg_replace('/<[^>]*>/', ' ', $text) ?? $text;
        $text .= ' ' . implode(' ', array_filter(array_merge($alts[1] ?? [], $alts[2] ?? [])));

        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The wording inside a tool call's arguments.
     *
     * Only the values that end up on the page as words: an address, a class
     * name, a colour or a file path is not wording, and each of them reads as
     * gibberish by these rules because none of them is language.
     *
     * @param  array<string, mixed> $arguments
     */
    public static function inArguments(array $arguments): string
    {
        $text = '';

        foreach ($arguments as $key => $value) {
            if (in_array(mb_strtolower((string) $key), self::NOT_WORDING, true)) {
                continue;
            }

            if (is_array($value)) {
                $text .= ' ' . self::inArguments($value);
                continue;
            }

            if (is_string($value)) {
                $text .= ' ' . self::visibleText($value);
            }
        }

        return $text;
    }

    /**
     * True if this word is spelled the way languages spell words.
     */
    private static function looksLikeAWord(string $word): bool
    {
        $plain = mb_strtolower($word);

        // An acronym is not spelled, it is said letter by letter: FAQ, SaaS,
        // API, WCAG.
        if ($word === mb_strtoupper($word) && mb_strlen($word) <= 6) {
            return true;
        }

        if (!self::hasAVowel($plain)) {
            return false;
        }

        $onset = self::leadingConsonants($plain);
        if (strlen($onset) >= 2 && !in_array($onset, self::ONSETS, true)) {
            return false;
        }

        // Five in a row is ordinary in a compound — witchcraft, nightshade,
        // birthplace, yachtsman — so the line is at six, where what is left is
        // "fruchtschiefer" and words no page has on it. A plural's own s makes
        // a run one longer than the word has: lengths, strengths, depths.
        $trunk = preg_replace('/s$/', '', $plain);
        if (preg_match('/[^' . self::VOWELS . ']{6,}/', $trunk)) {
            return false;
        }

        if (preg_match('/(.)\1\1/', $plain)) {
            return false;
        }

        return true;
    }

    private static function hasAVowel(string $plain): bool
    {
        return strcspn($plain, self::VOWELS) < strlen($plain);
    }

    private static function leadingConsonants(string $plain): string
    {
        return substr($plain, 0, strcspn($plain, self::VOWELS));
    }

    /**
     * Words worth examining: Latin letters only, four or more of them.
     *
     * Anything in another script is left alone — these rules are about the way
     * Latin letters go together, and a Thai or Japanese page would otherwise
     * be reported as gibberish from end to end. Short words are left alone
     * too: three letters is not enough to be wrong in a way that can be told
     * apart from an abbreviation.
     *
     * @return array<int, string>
     */
    private static function tokens(string $text): array
    {
        // An address is not wording, and its host and path read as nonsense.
        $text = preg_replace('#\b(?:https?://|www\.)\S+#i', ' ', $text) ?? $text;
        // A word next to a character of another script is part of that
        // script's writing, not a Latin word.
        preg_match_all('/(?<![\p{L}\p{M}])[A-Za-z][A-Za-z\']*(?![\p{L}\p{M}])/u', $text, $matches);

        return array_values(array_filter(
            $matches[0] ?? [],
            fn ($word) => strlen(str_replace("'", '', $word)) >= 4
        ));
    }

    /**
     * @return array<string, true>
     */
    private static function wordsOf(string $text): array
    {
        $words = [];

        preg_match_all('/[A-Za-z][A-Za-z\']*/u', $text, $matches);

        foreach ($matches[0] ?? [] as $word) {
            $words[mb_strtolower($word)] = true;
        }

        return $words;
    }
}
