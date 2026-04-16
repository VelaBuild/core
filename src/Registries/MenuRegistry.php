<?php

namespace VelaBuild\Core\Registries;

class MenuRegistry
{
    protected array $items = [];

    public function register(string $name, array $config): void
    {
        $this->items[$name] = array_merge([
            'label' => $name,
            'icon' => 'fa-circle',
            'route' => '#',
            'gate' => null,
            'group' => 'general',
            'order' => 999,
            'children' => [],
        ], $config);
    }

    public function get(string $name): ?array
    {
        return $this->items[$name] ?? null;
    }

    public function all(): array
    {
        return $this->items;
    }

    public function grouped(): array
    {
        $grouped = [];
        foreach ($this->items as $name => $item) {
            $grouped[$item['group']][$name] = $item;
        }
        foreach ($grouped as &$group) {
            uasort($group, fn ($a, $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));
        }
        return $grouped;
    }
}
