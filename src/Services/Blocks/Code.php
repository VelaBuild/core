<?php

namespace VelaBuild\Core\Services\Blocks;

/**
 * A code snippet's settings, made safe for the page.
 */
class Code
{
    /**
     * The languages a snippet can be in: the stored key, the name shown to a
     * reader, and what highlight.js calls it.
     *
     * The key is also the `language-*` class on the <code>, which is the
     * convention every highlighter reads.
     */
    public const LANGUAGES = [
        'text'       => ['Plain text', null],
        'bash'       => ['Shell', 'bash'],
        'php'        => ['PHP', 'php'],
        'js'         => ['JavaScript', 'javascript'],
        'ts'         => ['TypeScript', 'typescript'],
        'json'       => ['JSON', 'json'],
        'html'       => ['HTML', 'xml'],
        'xml'        => ['XML', 'xml'],
        'css'        => ['CSS', 'css'],
        'scss'       => ['SCSS', 'scss'],
        'sql'        => ['SQL', 'sql'],
        'yaml'       => ['YAML', 'yaml'],
        'python'     => ['Python', 'python'],
        'go'         => ['Go', 'go'],
        'java'       => ['Java', 'java'],
        'ruby'       => ['Ruby', 'ruby'],
        'markdown'   => ['Markdown', 'markdown'],
        'diff'       => ['Diff', 'diff'],
    ];

    /** How tall a snippet may stand before it is folded, in lines. */
    public const HEIGHTS = ['full' => 0, 'medium' => 16, 'short' => 8];

    public static function settings(array $raw): array
    {
        $bool = function (string $key, bool $default) use ($raw) {
            // Absent means the block was saved before the choice existed.
            return array_key_exists($key, $raw) ? filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN) : $default;
        };

        // A setting can arrive as anything at all — an AI build, an import, a
        // hand-edited JSON — so a key is only looked up once it is a string.
        $pick = function (string $key, array $allowed, string $default) use ($raw) {
            $value = $raw[$key] ?? null;

            return is_string($value) && isset($allowed[$value]) ? $value : $default;
        };

        return [
            'language'      => $pick('language', self::LANGUAGES, 'bash'),
            'theme'         => in_array($raw['theme'] ?? null, ['dark', 'light'], true) ? $raw['theme'] : 'dark',
            'show_copy'     => $bool('show_copy', true),
            'line_numbers'  => $bool('line_numbers', false),
            // Long lines scroll sideways unless they are told to wrap; line
            // numbers cannot line up with wrapped text, so wrapping wins and
            // the gutter is dropped.
            'wrap'          => $bool('wrap', false),
            'max_height'    => $pick('max_height', self::HEIGHTS, 'full'),
        ];
    }

    /** What highlight.js should call this language, or null to leave it alone. */
    public static function highlightName(string $language): ?string
    {
        return self::LANGUAGES[$language][1] ?? null;
    }

    public static function label(string $language): string
    {
        return self::LANGUAGES[$language][0] ?? $language;
    }

    public static function lineCount(string $code): int
    {
        return substr_count(rtrim($code, "\n"), "\n") + 1;
    }

    /**
     * Whether the snippet is folded: taller than the chosen height, so the
     * page shows the first lines and a button for the rest.
     */
    public static function folds(array $s, string $code): bool
    {
        $limit = self::HEIGHTS[$s['max_height']] ?? 0;

        return $limit > 0 && self::lineCount($code) > $limit;
    }
}
