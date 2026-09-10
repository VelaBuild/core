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
            // 'js' where page-editor.js hand-writes the form; otherwise the
            // admin builds one from `fields`. Null means neither, which means
            // the block renders but cannot be edited — see editableNames().
            'editor' => null,
            'fields' => [],
            'editor_note' => null,
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
     * the admin needs a form, and five had none: a page built from those
     * showed "Unknown block type" and its owner could never change a word of
     * it. There are two ways to have a form. `'editor' => 'js'` says one is
     * hand-written in page-editor.js, for the blocks whose editing is genuinely
     * bespoke — a rich-text canvas, a media browser, a repeater of pricing
     * tiers. `'fields' => [...]` describes a plain form the admin builds
     * itself, which is all the rest need.
     *
     * This used to be answered by regex over page-editor.js, reading back what
     * the JS happened to register. That made a 324KB file the authority on a
     * question PHP has to answer, so a block was editable by accident rather
     * than by declaration, and nothing noticed a block that had neither.
     * BlockManifestTest checks the two halves still agree.
     */
    public function editableNames(): array
    {
        return array_keys(array_filter($this->blocks, fn (array $config) => $this->hasForm($config)));
    }

    public function isEditable(string $name): bool
    {
        $config = $this->blocks[$name] ?? null;

        return $config !== null && $this->hasForm($config);
    }

    /** Whether a registration describes a way to edit the block. */
    private function hasForm(array $config): bool
    {
        return ($config['editor'] ?? null) === 'js' || !empty($config['fields']);
    }

    /**
     * The field schemas the admin needs to build forms, keyed by block type.
     *
     * Only blocks without a hand-written editor appear.
     */
    public function fieldSchemas(): array
    {
        $out = [];

        foreach ($this->blocks as $name => $config) {
            if (($config['editor'] ?? null) === 'js' || empty($config['fields'])) {
                continue;
            }

            $out[$name] = [
                'label'    => $config['label'] ? (string) trans($config['label']) : $name,
                'icon'     => $config['icon'] ?? 'fa-puzzle-piece',
                'defaults' => $config['defaults'] ?? ['content' => [], 'settings' => []],
                'fields'   => $config['fields'],
                'note'     => $config['editor_note'] ?? null,
            ];
        }

        return $out;
    }
}
