<?php

namespace VelaBuild\Core\Registries;

class ProfileMenuRegistry
{
    protected array $items = [];

    public function register(string $name, array $config): void
    {
        $this->items[$name] = array_merge([
            'label' => $name,
            'icon' => 'fas fa-circle',
            'route' => '#',
            'gate' => null,
            'order' => 999,
            'divider_before' => false,
        ], $config);
    }

    public function get(string $name): ?array
    {
        return $this->items[$name] ?? null;
    }

    public function all(): array
    {
        uasort($this->items, fn ($a, $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));

        return $this->items;
    }

    public function remove(string $name): void
    {
        unset($this->items[$name]);
    }
}
