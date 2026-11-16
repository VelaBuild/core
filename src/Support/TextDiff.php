<?php

namespace VelaBuild\Core\Support;

/**
 * A unified diff of two texts, for showing a person (or an AI) what changed.
 *
 * Uses `git diff --no-index` when git is on the server; without it, one hunk
 * covering everything between the common start and end.
 */
class TextDiff
{
    public static function unified(string $a, string $b, string $labelA, string $labelB): string
    {
        if ($a === $b) {
            return '';
        }

        $dir = sys_get_temp_dir() . '/vela-diff-' . bin2hex(random_bytes(6));
        if (@mkdir($dir, 0700)) {
            try {
                file_put_contents("{$dir}/a", $a);
                file_put_contents("{$dir}/b", $b);
                $out = self::run(['git', 'diff', '--no-index', '--no-color', '-U3', "{$dir}/a", "{$dir}/b"]);
                if ($out !== null && $out['code'] <= 1 && $out['stdout'] !== '') {
                    $lines = explode("\n", $out['stdout']);
                    // Drop git's header (diff --git / index / --- / +++) for our own labels.
                    while ($lines && !str_starts_with($lines[0], '@@')) {
                        array_shift($lines);
                    }

                    return "--- {$labelA}\n+++ {$labelB}\n" . implode("\n", $lines);
                }
            } finally {
                @unlink("{$dir}/a");
                @unlink("{$dir}/b");
                @rmdir($dir);
            }
        }

        $x = explode("\n", $a);
        $y = explode("\n", $b);
        $start = 0;
        while ($start < count($x) && $start < count($y) && $x[$start] === $y[$start]) {
            $start++;
        }
        $endX = count($x);
        $endY = count($y);
        while ($endX > $start && $endY > $start && $x[$endX - 1] === $y[$endY - 1]) {
            $endX--;
            $endY--;
        }
        $body = array_merge(
            array_map(fn ($l) => '-' . $l, array_slice($x, $start, $endX - $start)),
            array_map(fn ($l) => '+' . $l, array_slice($y, $start, $endY - $start)),
        );

        return "--- {$labelA}\n+++ {$labelB}\n@@ -" . ($start + 1) . ',' . ($endX - $start) . ' +' . ($start + 1) . ',' . ($endY - $start) . " @@\n" . implode("\n", $body) . "\n";
    }

    /**
     * A unified diff as hunks of lines with their line numbers, for display.
     *
     * @return array<int, array{header: string, lines: array<int, array{type: string, old: ?int, new: ?int, text: string}>}>
     *         type: context, del, add or note (Git's "\ No newline at end of file")
     */
    public static function parse(string $diff): array
    {
        $hunks = [];
        $old = $new = 0;

        foreach (explode("\n", $diff) as $line) {
            if (preg_match('/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@(.*)$/', $line, $m)) {
                $old = (int) $m[1];
                $new = (int) $m[2];
                $hunks[] = ['header' => trim($m[3]), 'lines' => []];
                continue;
            }
            if ($hunks === []) {
                continue; // the --- / +++ labels before the first hunk
            }

            $current = &$hunks[count($hunks) - 1]['lines'];
            $mark = $line[0] ?? ' ';
            $text = (string) substr($line, 1);

            if ($mark === '-') {
                $current[] = ['type' => 'del', 'old' => $old++, 'new' => null, 'text' => $text];
            } elseif ($mark === '+') {
                $current[] = ['type' => 'add', 'old' => null, 'new' => $new++, 'text' => $text];
            } elseif ($mark === '\\') {
                $current[] = ['type' => 'note', 'old' => null, 'new' => null, 'text' => trim($text)];
            } elseif ($line !== '') {
                // An unchanged line, even an empty one, starts with a space;
                // '' is only what explode() leaves after the last newline.
                $current[] = ['type' => 'context', 'old' => $old++, 'new' => $new++, 'text' => $text];
            }
            unset($current);
        }

        return $hunks;
    }

    /**
     * A hunk's lines side by side: each removed line opposite the line that
     * replaced it, unchanged lines on both sides.
     *
     * @param array<int, array{type: string, old: ?int, new: ?int, text: string}> $lines
     *
     * @return array<int, array{left: ?array, right: ?array}>
     */
    public static function sideBySide(array $lines): array
    {
        $rows = [];
        $dels = $adds = [];

        $flush = function () use (&$rows, &$dels, &$adds) {
            for ($i = 0, $n = max(count($dels), count($adds)); $i < $n; $i++) {
                $rows[] = ['left' => $dels[$i] ?? null, 'right' => $adds[$i] ?? null];
            }
            $dels = $adds = [];
        };

        foreach ($lines as $line) {
            if ($line['type'] === 'del') {
                if ($adds !== []) {
                    $flush();
                }
                $dels[] = $line;
            } elseif ($line['type'] === 'add') {
                $adds[] = $line;
            } elseif ($line['type'] === 'note') {
                continue;
            } else {
                $flush();
                $rows[] = ['left' => $line, 'right' => $line];
            }
        }
        $flush();

        return $rows;
    }

    /** @return array{stdout: string, code: int}|null */
    private static function run(array $cmd): ?array
    {
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        // 127: command not found (proc_open runs via execvp, so no shell message).
        return $code === 127 ? null : ['stdout' => (string) $stdout, 'code' => $code];
    }
}
