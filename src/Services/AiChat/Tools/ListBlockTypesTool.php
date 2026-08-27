<?php

namespace VelaBuild\Core\Services\AiChat\Tools;

use VelaBuild\Core\Models\AiActionLog;

class ListBlockTypesTool extends BaseTool
{
    public function execute(array $parameters, ?AiActionLog $actionLog = null): array
    {
        $blocks = app(\VelaBuild\Core\Vela::class)->blocks()->all();

        $types = [];
        foreach ($blocks as $name => $def) {
            $entry = [
                'type'             => $name,
                'label'            => $def['label'] ?? $name,
                'default_content'  => $def['defaults']['content'] ?? null,
                'default_settings' => $def['defaults']['settings'] ?? null,
            ];

            // List-style blocks default to an empty array, which says nothing
            // about what one entry holds. Where the block declares an example,
            // pass it through — it is the only description of that shape.
            if (!empty($def['content_example'])) {
                $entry['content_example'] = $def['content_example'];
            }

            // Constraints the key names alone do not convey, e.g. that a
            // contact form stores submissions rather than emailing them.
            if (!empty($def['shape_note'])) {
                $entry['note'] = $def['shape_note'];
            }

            // The class names the block actually renders with. Without them a
            // theme cannot style a block to match a design — it would be
            // guessing at selectors, and CSS that matches nothing is silent.
            // A block with no editor in the admin renders for a visitor and
            // shows "Unknown block type" to the person who owns the page.
            $entry['editable_in_admin'] = app(\VelaBuild\Core\Vela::class)->blocks()->isEditable($name);

            $classes = $this->cssClasses($name);
            if ($classes) {
                $entry['css_classes'] = $classes;
            }

            $types[] = $entry;
        }

        return [
            'success' => true,
            'types'   => $types,
        ];
    }

    /**
     * The stable class names a block type renders with.
     *
     * Read from the view rather than kept in a list, so they cannot drift
     * apart from what the block really emits. Anything a Blade expression
     * builds at render time is trimmed back to its literal prefix, which is
     * the part a stylesheet can rely on.
     */
    private function cssClasses(string $type): array
    {
        $view = __DIR__ . '/../../../../resources/views/public/pages/blocks/' . $type . '.blade.php';

        if (!is_file($view)) {
            return [];
        }

        preg_match_all('/class="([^"]*)"/', (string) file_get_contents($view), $matches);

        $classes = [];

        foreach ($matches[1] as $attribute) {
            // Cut the attribute at the first Blade expression: what precedes
            // it is fixed, what follows depends on the block's settings.
            $literal = explode('{{', $attribute)[0];

            foreach (preg_split('/\s+/', trim($literal)) ?: [] as $class) {
                if ($class !== '' && !in_array($class, $classes, true)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }
}
