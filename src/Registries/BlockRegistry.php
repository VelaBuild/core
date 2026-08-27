<?php

namespace VelaBuild\Core\Registries;

use Illuminate\Support\Facades\Log;

class BlockRegistry
{
    protected array $blocks = [];

    public function register(string $name, array $config): void
    {
        if (isset($this->blocks[$name])) {
            Log::warning("Vela: Block type '{$name}' is being overridden by a new registration.");
        }

        $this->blocks[$name] = array_merge([
            'label' => $name,
            'icon' => 'fa-puzzle-piece',
            'view' => null,
            'editor' => null,
            'defaults' => ['content' => [], 'settings' => []],
        ], $config);
    }

    public function get(string $name): ?array
    {
        return $this->blocks[$name] ?? null;
    }

    public function all(): array
    {
        return $this->blocks;
    }

    public function has(string $name): bool
    {
        return isset($this->blocks[$name]);
    }

    public function names(): array
    {
        return array_keys($this->blocks);
    }

    /**
     * Block types the page editor can actually edit.
     *
     * A block registered here renders on the public site, but editing one in
     * the admin needs a matching registerBlockType() in page-editor.js, and
     * several have none — a page built from those shows "Unknown block type"
     * and its owner can never change a word of it. The editor's own file is
     * the authority, so it is read rather than duplicated into a list beside
     * it that would drift.
     */
    public function editableNames(): array
    {
        static $editable = null;

        if ($editable !== null) {
            return $editable;
        }

        $script = __DIR__ . '/../../public/js/page-editor.js';

        if (!is_file($script)) {
            return $editable = $this->names();
        }

        preg_match_all("/registerBlockType\(\s*'([a-z0-9_-]+)'/i", (string) file_get_contents($script), $matches);

        $found = array_values(array_unique($matches[1]));

        // Nothing found means the file changed shape, and silently declaring
        // every block uneditable would be worse than assuming they are fine.
        return $editable = $found ?: $this->names();
    }

    public function isEditable(string $name): bool
    {
        return in_array($name, $this->editableNames(), true);
    }
}
