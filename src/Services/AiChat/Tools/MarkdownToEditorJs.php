<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

/**
 * Convert AI-emitted markdown to the EditorJS JSON shape stored in the DB.
 * Shared between CreateArticleTool, EditArticleContentTool, and any future
 * tool that takes markdown as input.
 *
 * Handles:
 *   - ATX headings (#, ##, … ######), strips a wrapping **bold** if present
 *   - Unordered lists with `-`, `*`, or `+` bullets
 *   - Ordered lists `1. …`
 *   - Pipe-style markdown tables (with alignment row skipped)
 *   - Horizontal rules (`---`, `***`, `___`) → delimiter block
 *   - Inline: **bold**, __bold__, *em*, _em_, `code`, [text](url)
 *   - [IMAGE topic="…" alt="…"] passthrough
 */
class MarkdownToEditorJs
{
    /**
     * Normalize whatever the AI passed for a page-builder TEXT block into the
     * canonical EditorJS document the editor (page-editor.js), the renderer, and
     * the admin preview all read: `['blocks' => [...]]`.
     *
     * The text block is EditorJS-backed, but the AI commonly passes a plain
     * string, `{text: "markdown"}`, `{body: ...}`, or `{html: ...}`. Anything
     * without a proper `blocks` array renders/edits as empty, so convert it.
     */
    public static function textBlockContent($content): array
    {
        // Already a valid EditorJS document — keep as-is.
        if (is_array($content) && isset($content['blocks']) && is_array($content['blocks'])) {
            return $content;
        }

        $text = '';
        if (is_string($content)) {
            $text = $content;
        } elseif (is_array($content)) {
            foreach (['text', 'body', 'markdown', 'html', 'content'] as $key) {
                if (!empty($content[$key]) && is_string($content[$key])) {
                    $text = $content[$key];
                    break;
                }
            }
        }

        $decoded = json_decode(self::convert($text), true);
        return is_array($decoded) ? $decoded : ['blocks' => []];
    }

    /**
     * Derive the article's one-line summary from its markdown.
     *
     * The summary is printed verbatim on the article listing and fed to search
     * engines, so truncating the raw source put "# Introduction" on the page
     * for every visitor to read. Take the first real paragraph instead — the
     * opening heading is almost always a restatement of the title.
     */
    public static function excerpt(string $contentText, int $limit = 160): string
    {
        $document = json_decode(self::convert($contentText), true);

        $text = '';
        foreach ($document['blocks'] ?? [] as $block) {
            if (($block['type'] ?? '') !== 'paragraph') {
                continue;
            }

            $candidate = trim(html_entity_decode(strip_tags($block['data']['text'] ?? ''), ENT_QUOTES, 'UTF-8'));
            if ($candidate !== '') {
                $text = $candidate;
                break;
            }
        }

        // Content with no paragraph at all (a bare list, say) still deserves a
        // summary, so fall back to the source with its markup taken off.
        if ($text === '') {
            $text = trim(preg_replace('/[#*_>`\[\]]|^\s*[-+]\s+/mu', ' ', strip_tags($contentText)));
        }

        return \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', $text), $limit);
    }

    public static function convert(string $contentText): string
    {
        $lines = explode("\n", $contentText);
        $blocks = [];
        $blockId = 1;
        $currentList = null;
        $tableRows = null;

        $flushList = function () use (&$currentList, &$blocks) {
            if ($currentList) {
                $blocks[] = $currentList;
                $currentList = null;
            }
        };
        $flushTable = function () use (&$tableRows, &$blocks, &$blockId) {
            if ($tableRows) {
                $blocks[] = [
                    'id'   => 'table-' . $blockId++,
                    'type' => 'table',
                    'data' => ['withHeadings' => true, 'content' => $tableRows],
                ];
                $tableRows = null;
            }
        };

        foreach ($lines as $rawLine) {
            $line = rtrim($rawLine);
            $trimmed = trim($line);

            if ($trimmed === '') {
                $flushList();
                // DON'T flush a table on blank lines. Some models emit a blank
                // line between every row which would otherwise turn each row
                // into a one-row table block. Tables only flush when we hit a
                // real non-table content line below.
                continue;
            }

            if (preg_match('/^(\s*)([-*_]\s*){3,}\s*$/', $line) && !preg_match('/[A-Za-z0-9]/', $trimmed)) {
                $flushList();
                $flushTable();
                $blocks[] = [
                    'id'   => 'delimiter-' . $blockId++,
                    'type' => 'delimiter',
                    'data' => new \stdClass(),
                ];
                continue;
            }

            if (str_starts_with($trimmed, '|')) {
                $flushList();
                if (preg_match('/^\|?\s*[:\- ]+\s*\|/', $trimmed)) {
                    continue;
                }
                $cells = array_map('trim', explode('|', trim($trimmed, '|')));
                $cells = array_map(fn ($c) => self::processInlineFormatting($c), $cells);
                $tableRows = $tableRows ?? [];
                $tableRows[] = $cells;
                continue;
            } else {
                // Real content line that isn't a table row → table sequence ends.
                $flushTable();
            }

            if (preg_match('/\[IMAGE\s+topic="([^"]+)"\s+alt="([^"]+)"\]/i', $trimmed)) {
                $flushList();
                $blocks[] = [
                    'id'   => 'paragraph-' . $blockId++,
                    'type' => 'paragraph',
                    'data' => ['text' => $trimmed],
                ];
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m)) {
                $flushList();
                $level = min(strlen($m[1]), 6);
                $text = trim($m[2]);
                if (preg_match('/^\*\*(.+)\*\*$/', $text, $bm)) {
                    $text = $bm[1];
                }
                $blocks[] = [
                    'id'   => 'heading-' . $blockId++,
                    'type' => 'header',
                    'data' => [
                        'text'  => self::processInlineFormatting($text),
                        'level' => $level,
                    ],
                ];
                continue;
            }

            if (preg_match('/^[\-\*\+]\s+(.+)$/', $trimmed, $m)) {
                if (!$currentList || $currentList['type'] !== 'list' || $currentList['data']['style'] !== 'unordered') {
                    $flushList();
                    $currentList = [
                        'id'   => 'list-' . $blockId++,
                        'type' => 'list',
                        'data' => ['style' => 'unordered', 'items' => []],
                    ];
                }
                $currentList['data']['items'][] = self::processInlineFormatting($m[1]);
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $m)) {
                if (!$currentList || $currentList['type'] !== 'list' || $currentList['data']['style'] !== 'ordered') {
                    $flushList();
                    $currentList = [
                        'id'   => 'list-' . $blockId++,
                        'type' => 'list',
                        'data' => ['style' => 'ordered', 'items' => []],
                    ];
                }
                $currentList['data']['items'][] = self::processInlineFormatting($m[1]);
                continue;
            }

            $flushList();
            $blocks[] = [
                'id'   => 'paragraph-' . $blockId++,
                'type' => 'paragraph',
                'data' => ['text' => self::processInlineFormatting($trimmed)],
            ];
        }

        if ($currentList) $blocks[] = $currentList;
        if ($tableRows) {
            $blocks[] = [
                'id'   => 'table-' . $blockId++,
                'type' => 'table',
                'data' => ['withHeadings' => true, 'content' => $tableRows],
            ];
        }

        return json_encode([
            'time'   => time() * 1000,
            'blocks' => $blocks,
        ]);
    }

    public static function processInlineFormatting(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<![\*_])\*(?!\s)(.+?)(?<!\s)\*(?![\*_])/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<![\*_])_(?!\s)(.+?)(?<!\s)_(?![\*_])/', '<em>$1</em>', $text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2">$1</a>', $text);
        return $text;
    }
}
