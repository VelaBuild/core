<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Services\AiChat\StyleConflictDetector;

class GetPageBlocksTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $page = $this->resolvePage($parameters);
        if (!$page) {
            return ['error' => 'Page not found. Pass page_id or page_slug.'];
        }

        $page->load('rows.blocks');

        $rows = $page->rows->sortBy('order_column')->values()->map(function ($row) {
            return [
                'id'               => $row->id,
                'name'             => $row->name,
                'css_class'        => $row->css_class,
                'background_color' => $row->background_color,
                'background_image' => $row->background_image,
                'text_color'       => $row->text_color,
                'text_alignment'   => $row->text_alignment,
                'padding'          => $row->padding,
                'width'            => $row->width,
                'order'            => $row->order_column,
                'blocks'           => $row->blocks->sortBy('order_column')->values()->map(function ($block) {
                    return [
                        'id'           => $block->id,
                        'type'         => $block->type,
                        'column_index' => $block->column_index,
                        'column_width' => $block->column_width,
                        'order'        => $block->order_column,
                        'content'      => $block->content,
                        'settings'     => $block->settings,
                        'background_image' => $block->background_image,
                        'text_color'   => $block->text_color,
                        // What CSS can actually target. Without this the model
                        // invents selectors like `.button` or `#page-id-10`,
                        // which match nothing and silently do nothing.
                        'css_selector' => '#block-' . $block->id,
                        'css_classes'  => self::classesFor($block->type),
                    ];
                })->toArray(),
            ];
        })->toArray();

        // Advanced settings CSS ships with the blocks. Read apart from it, a
        // block record says the background image is set while the page shows
        // none, and the answer is always a rule in here painting over it.
        $pageCss = (string) ($page->custom_css ?? '');
        $siteCss = (string) (VelaConfig::where('key', 'custom_css_global')->first()?->value ?? '');

        $conflicts = StyleConflictDetector::detect(self::imageTargets($rows), [
            'page custom CSS (Advanced settings)' => $pageCss,
            'site-wide custom CSS' => $siteCss,
        ]);

        return [
            'success' => true,
            'page'    => [
                'id'     => $page->id,
                'title'  => $page->title,
                'slug'   => $page->slug,
                'locale' => $page->locale,
                'status' => $page->status,
            ],
            'rows'    => $rows,
            'custom_css' => [
                'page' => $pageCss,
                'site' => $siteCss,
                'edited_at' => 'Admin: page Advanced settings (page scope) / Settings > Custom CSS (site scope). '
                    . 'Change it with update_custom_css.',
            ],
            'style_conflicts' => $conflicts,
            'style_conflicts_note' => $conflicts
                ? 'Custom CSS is hiding something this page is configured to show. Report these before anything else '
                    . 'when someone says an image or colour is not appearing.'
                : 'No custom CSS rule was found covering a row or block background image.',
            'note'    => "A row's `name` is an INTERNAL admin label only — it is NOT rendered on the page. "
                . "To show a visible heading/title to visitors, the row must contain an actual content block "
                . "(a 'text' block whose content includes the heading, or a hero/cta block with a title field). "
                . "If a section has a name but no block carrying that text, visitors see no title.",
        ];
    }

    /**
     * Selector => image URL for every row and block carrying a background
     * image, which is what custom CSS can end up covering.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private static function imageTargets(array $rows): array
    {
        $targets = [];

        foreach ($rows as $row) {
            if (!empty($row['background_image'])) {
                $targets['#row-' . $row['id']] = (string) $row['background_image'];
            }
            foreach ($row['blocks'] as $block) {
                if (!empty($block['background_image'])) {
                    $targets['#block-' . $block['id']] = (string) $block['background_image'];
                }
            }
        }

        return $targets;
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

    /**
     * The class names a block type actually renders, read from its view.
     *
     * @return array<int, string>
     */
    private static function classesFor(string $type): array
    {
        static $cache = [];

        if (array_key_exists($type, $cache)) {
            return $cache[$type];
        }

        $view = __DIR__ . '/../../../../resources/views/public/pages/blocks/' . $type . '.blade.php';
        if (!is_file($view)) {
            return $cache[$type] = [];
        }

        preg_match_all('/class="([a-z0-9 _-]+)"/i', file_get_contents($view), $matches);

        $classes = [];
        foreach ($matches[1] as $attribute) {
            foreach (preg_split('/\s+/', trim($attribute)) as $class) {
                if ($class !== '') {
                    $classes[$class] = true;
                }
            }
        }

        return $cache[$type] = array_keys($classes);
    }
}
