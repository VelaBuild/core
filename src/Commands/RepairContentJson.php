<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Models\Content;

class RepairContentJson extends Command
{
    protected $signature = 'vela:repair-content-json {--dry-run : Show what would be repaired without writing}';
    protected $description = 'Strip invalid JSON escape sequences (\\d, \\., etc.) from article content so EditorJS can parse it.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $repaired = 0;
        $unfixable = 0;
        $total = 0;

        Content::where('type', 'post')->chunkById(100, function ($posts) use (&$repaired, &$unfixable, &$total, $dry) {
            foreach ($posts as $post) {
                $total++;
                $raw = $post->content;
                if (!$raw || !is_string($raw)) continue;

                if (json_decode($raw) !== null && json_last_error() === JSON_ERROR_NONE) {
                    continue; // already valid
                }

                $fixed = preg_replace('/\\\\([^"\\\\\/bfnrtu])/', '$1', $raw);
                // Repair scheme URLs that lost a slash (https:/foo -> https://foo)
                $fixed = preg_replace('#(https?:)\\\\/(?!\\\\/)#', '$1\\/\\/', $fixed);
                $fixed = preg_replace('#(https?:)/(?!/)#', '$1//', $fixed);
                if (json_decode($fixed) !== null && json_last_error() === JSON_ERROR_NONE) {
                    if (!$dry) {
                        // Bypass the mutator (already sanitized) and skip
                        // observers so we don't trigger a static regen storm.
                        Content::withoutEvents(function () use ($post, $fixed) {
                            $post->setRawAttributes(array_merge($post->getAttributes(), ['content' => $fixed]));
                            $post->saveQuietly();
                        });
                    }
                    $this->line(($dry ? '[DRY] ' : '') . "  repaired: {$post->slug}");
                    $repaired++;
                } else {
                    $this->warn("  unfixable: {$post->slug} (still invalid JSON after strip pass)");
                    $unfixable++;
                }
            }
        });

        $this->newLine();
        $this->info("Scanned {$total} posts. Repaired {$repaired}. Unfixable {$unfixable}.");
        return self::SUCCESS;
    }
}
