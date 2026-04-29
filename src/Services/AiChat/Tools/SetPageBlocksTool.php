<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use Illuminate\Support\Facades\DB;
use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\PageBlock;
use VelaBuild\Core\Models\PageRow;

class SetPageBlocksTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $page = $this->resolvePage($parameters);
        if (!$page) {
            return ['error' => 'Page not found. Pass page_id or page_slug.'];
        }

        $rows = $parameters['rows'] ?? null;
        if (!is_array($rows)) {
            return ['error' => 'rows parameter must be an array'];
        }

        $registered = array_keys(app(\VelaBuild\Core\Vela::class)->blocks()->all());

        // Snapshot existing structure for undo before we touch anything.
        $page->load('rows.blocks');
        if ($actionLog) {
            $actionLog->update([
                'previous_state' => [
                    'page_id' => $page->id,
                    'rows'    => $page->rows->map(function ($r) {
                        return [
                            'name'             => $r->name,
                            'css_class'        => $r->css_class,
                            'background_color' => $r->background_color,
                            'background_image' => $r->background_image,
                            'text_color'       => $r->text_color,
                            'text_alignment'   => $r->text_alignment,
                            'padding'          => $r->padding,
                            'width'            => $r->width,
                            'order_column'     => $r->order_column,
                            'blocks'           => $r->blocks->map(function ($b) {
                                return [
                                    'column_index' => $b->column_index,
                                    'column_width' => $b->column_width,
                                    'order_column' => $b->order_column,
                                    'type'         => $b->type,
                                    'content'      => $b->content,
                                    'settings'     => $b->settings,
                                ];
                            })->toArray(),
                        ];
                    })->toArray(),
                ],
            ]);
        }

        $unknownTypes = [];
        $createdRows = 0;
        $createdBlocks = 0;

        $touchPage = function () use ($page) {
            // Touch the parent Page so PageObserver::saved fires and the
            // static cache regeneration job runs — otherwise rebuilds via
            // this tool leave home/index.html and config.json out of sync
            // with the new block structure.
            $page->touch();
        };

        DB::transaction(function () use ($page, $rows, $registered, &$createdRows, &$createdBlocks, &$unknownTypes) {
            // Wipe current rows + blocks. cascade via row->blocks cleanup.
            foreach ($page->rows as $row) {
                $row->blocks()->delete();
                $row->delete();
            }

            foreach ($rows as $rowOrder => $rowData) {
                $row = PageRow::create([
                    'page_id'          => $page->id,
                    'name'             => $rowData['name'] ?? null,
                    'css_class'        => $rowData['css_class'] ?? null,
                    'background_color' => $rowData['background_color'] ?? null,
                    'background_image' => $rowData['background_image'] ?? null,
                    'text_color'       => $rowData['text_color'] ?? null,
                    'text_alignment'   => $rowData['text_alignment'] ?? null,
                    'padding'          => $rowData['padding'] ?? null,
                    'width'            => in_array($rowData['width'] ?? null, ['full', 'contained'], true) ? $rowData['width'] : 'contained',
                    'order_column'     => $rowData['order'] ?? $rowOrder,
                ]);
                $createdRows++;

                foreach ($rowData['blocks'] ?? [] as $blockOrder => $blockData) {
                    $type = $blockData['type'] ?? null;
                    if (!$type || !in_array($type, $registered, true)) {
                        $unknownTypes[] = $type ?? '(missing)';
                        continue;
                    }

                    PageBlock::create([
                        'page_row_id'  => $row->id,
                        'column_index' => $blockData['column_index'] ?? 0,
                        'column_width' => $blockData['column_width'] ?? 12,
                        'order_column' => $blockData['order'] ?? $blockOrder,
                        'type'         => $type,
                        'content'      => $blockData['content'] ?? null,
                        'settings'     => $blockData['settings'] ?? null,
                    ]);
                    $createdBlocks++;
                }
            }
        });

        $touchPage();

        return [
            'success'        => true,
            'page_id'        => $page->id,
            'rows_created'   => $createdRows,
            'blocks_created' => $createdBlocks,
            'unknown_types'  => $unknownTypes,
            'note'           => $unknownTypes
                ? 'Some blocks were skipped because their type is not registered. Use list_block_types to see allowed types.'
                : null,
        ];
    }

    public function undo(AiActionLog $actionLog): void
    {
        $previous = $actionLog->previous_state ?? null;
        if (!is_array($previous) || empty($previous['page_id'])) {
            throw new \RuntimeException('No previous state to undo.');
        }

        $page = Page::find($previous['page_id']);
        if (!$page) {
            throw new \RuntimeException('Page no longer exists.');
        }

        DB::transaction(function () use ($page, $previous) {
            foreach ($page->rows as $row) {
                $row->blocks()->delete();
                $row->delete();
            }
            foreach ($previous['rows'] ?? [] as $rowData) {
                $row = PageRow::create(array_merge(['page_id' => $page->id], $rowData, ['blocks' => null]));
                foreach ($rowData['blocks'] ?? [] as $blockData) {
                    PageBlock::create(array_merge(['page_row_id' => $row->id], $blockData));
                }
            }
        });
    }

    private function resolvePage(array $params): ?Page
    {
        if (!empty($params['page_id'])) {
            return Page::find($params['page_id']);
        }
        if (!empty($params['page_slug'])) {
            $locale = $params['locale'] ?? config('vela.primary_language', 'en');
            return Page::where('slug', $params['page_slug'])->where('locale', $locale)->first()
                ?? Page::where('slug', $params['page_slug'])->first();
        }
        return null;
    }
}
