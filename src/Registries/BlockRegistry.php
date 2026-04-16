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
}
