<?php

namespace VelaBuild\Core\Commands;

use Illuminate\Console\Command;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;
use VelaBuild\Core\Services\AiChat\Tools\MarkdownToEditorJs;

/**
 * Backfill page-builder TEXT blocks whose content was stored in a non-EditorJS
 * shape (e.g. {text: "..."} written by the AI) into the canonical
 * {blocks: [...]} the page builder and renderer read. Blocks already in the
 * right shape are left alone; truly-empty ones are reported, not touched.
 */
class FixTextBlocks extends Command
{
    protected $signature = 'vela:fix-text-blocks {--dry-run : Report what would change without writing}';

    protected $description = 'Convert text blocks stored in the wrong shape into canonical EditorJS content';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $fixed = 0;
        $emptied = 0;
        $skipped = 0;
        $pageIds = [];

        PageBlock::where('type', 'text')->chunkById(200, function ($blocks) use (&$fixed, &$emptied, &$skipped, &$pageIds, $dry) {
            foreach ($blocks as $block) {
                $content = $block->content;

                // Already valid EditorJS with content — nothing to do.
                if (is_array($content) && !empty($content['blocks'])) {
                    $skipped++;
                    continue;
                }

                $normalized = MarkdownToEditorJs::textBlockContent($content);

                if (empty($normalized['blocks'])) {
                    // No recoverable text under text/body/html/string.
                    $emptied++;
                    $this->line("  <comment>empty</comment>   block #{$block->id} — no recoverable text");
                    continue;
                }

                $this->line("  <info>recover</info> block #{$block->id} — " . count($normalized['blocks']) . ' block(s)');
                if (!$dry) {
                    $block->content = $normalized;
                    $block->save();
                    if ($pid = optional(PageRow::find($block->page_row_id))->page_id) {
                        $pageIds[$pid] = true;
                    }
                }
                $fixed++;
            }
        });

        if (!$dry && !empty($pageIds)) {
            // Touch affected pages so PageObserver regenerates their static cache.
            Page::whereIn('id', array_keys($pageIds))->get()->each->touch();
        }

        $verb = $dry ? 'would recover' : 'recovered';
        $this->newLine();
        $this->info("Done. {$verb} {$fixed} text block(s); {$emptied} empty (no content to recover); {$skipped} already fine.");
        if ($dry) {
            $this->comment('Dry run — nothing written. Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
