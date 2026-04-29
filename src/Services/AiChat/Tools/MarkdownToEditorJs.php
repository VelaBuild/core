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
                $flushTable();
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
