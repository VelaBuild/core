<?php

namespace VelaBuild\Core\Registries;

class WidgetRegistry
{
    protected array $widgets = [];

    public function register(string $name, array $config): void
    {
        $this->widgets[$name] = array_merge([
            'label' => $name,
            'icon' => 'fas fa-cube',
            'view' => null,
            'gate' => null,
            'width' => 'col-md-6',
            'order' => 999,
            'data' => null, // callable that returns data for the widget
        ], $config);
    }

    public function get(string $name): ?array
    {
        return $this->widgets[$name] ?? null;
    }

    public function all(): array
    {
        return $this->widgets;
    }

    public function has(string $name): bool
    {
        return isset($this->widgets[$name]);
    }

    public function names(): array
    {
        return array_keys($this->widgets);
    }

    public function ordered(): array
    {
        $widgets = $this->widgets;
        uasort($widgets, fn ($a, $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));
        return $widgets;
    }
}
