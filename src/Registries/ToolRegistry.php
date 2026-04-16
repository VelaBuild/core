<?php

namespace VelaBuild\Core\Registries;

use Illuminate\Support\Facades\Log;

class ToolRegistry
{
    protected array $tools = [];

    public function register(string $name, array $config): void
    {
        if (isset($this->tools[$name])) {
            Log::warning("Vela: Tool '{$name}' is being overridden by a new registration.");
        }

        $this->tools[$name] = array_merge([
            'label'       => $name,
            'description' => '',
            'icon'        => 'fas fa-wrench',
            'route'       => '#',
            'gate'        => 'tools_access',
            'config_gate' => null,
            'category'    => 'general',
            'status'      => null, // callable returning 'connected'|'not_configured'|'error'
        ], $config);
    }

    public function get(string $name): ?array
    {
        return $this->tools[$name] ?? null;
    }

    public function all(): array
    {
        return $this->tools;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    public function names(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Return tools grouped by category, sorted alphabetically within each category.
     */
    public function categorized(): array
    {
        $grouped = [];
        foreach ($this->tools as $name => $tool) {
            $grouped[$tool['category']][$name] = $tool;
        }
        ksort($grouped);
        return $grouped;
    }
}
