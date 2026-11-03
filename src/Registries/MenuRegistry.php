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

    /**
     * Label of the menu item the given route belongs to, for the breadcrumb.
     *
     * An item registered as "vela.admin.pages.index" covers every
     * "vela.admin.pages.*" route, so the edit and create screens are named
     * after it too. When several items match, the most specific one wins.
     */
    public function activeLabel(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        $best = null;
        $bestLength = -1;

        $consider = function (array $entry) use ($routeName, &$best, &$bestLength) {
            $route = (string) ($entry['route'] ?? '');
            if ($route === '' || str_starts_with($route, '#') || str_starts_with($route, 'http')) {
                return;
            }
            $family = preg_replace('/\.index$/', '', $route);
            if ($routeName !== $route && ! str_starts_with($routeName, $family . '.')) {
                return;
            }
            if (strlen($route) > $bestLength) {
                $best = $entry['label'] ?? null;
                $bestLength = strlen($route);
            }
        };

        foreach ($this->items as $item) {
            $consider($item);
            foreach ($item['children'] ?? [] as $child) {
                $consider($child);
            }
        }

        return $best;
    }
}
