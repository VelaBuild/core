<?php

namespace VelaBuild\Core\Registries;

/**
 * Entries shown on the Settings dropdown, page-head switcher, and the
 * Settings index card grid. Owned by core's defaults but extensible so
 * plugins (Store, Marketplace, custom modules) can add their own without
 * forking admin Blade files.
 *
 *   Vela::registerSettingsItem('store', [
 *       'label'       => 'Store',
 *       'icon'        => 'fas fa-store',
 *       'description' => 'Products, orders, payments.',
 *       'route'       => 'vela.admin.store.settings.index',
 *       'gate'        => 'config_access',
 *       'order'       => 200,
 *   ]);
 *
 * Filtering is automatic — entries whose `route` is not registered, or
 * whose `gate` is denied, are hidden by all() callers.
 */
class SettingsNavRegistry
{
    /** @var array<string, array> */
    protected array $items = [];

    public function register(string $key, array $config): void
    {
        $this->items[$key] = array_merge([
            'key'         => $key,
            'label'       => $key,
            'icon'        => 'fas fa-cog',
            'description' => '',
            'route'       => null,        // route name OR ['name', 'param']
            'gate'        => 'config_access',
            'order'       => 999,
            'hidden'      => false,       // true → not shown in card grid (but still in nav)
        ], $config, ['key' => $key]);
    }

    public function get(string $key): ?array
    {
        return $this->items[$key] ?? null;
    }

    /**
     * All visible entries, ordered, with unresolvable routes filtered out.
     * Gate evaluation happens here too so views never see denied items.
     */
    public function all(bool $includeHidden = true): array
    {
        $visible = [];
        foreach ($this->items as $key => $item) {
            if (! $includeHidden && ($item['hidden'] ?? false)) continue;
            [$name] = $this->routeParts($item);
            if ($name && ! \Illuminate\Support\Facades\Route::has($name)) continue;
            if (! empty($item['gate']) && ! \Illuminate\Support\Facades\Gate::allows($item['gate'])) continue;
            $visible[$key] = $item;
        }
        uasort($visible, fn ($a, $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));
        return $visible;
    }

    public function url(array $item): ?string
    {
        [$name, $param] = $this->routeParts($item);
        if (! $name) return null;
        try {
            return $param !== null ? route($name, $param) : route($name);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @return array{0: string|null, 1: mixed}
     */
    protected function routeParts(array $item): array
    {
        $route = $item['route'] ?? null;
        if (is_array($route)) {
            return [$route[0] ?? null, $route[1] ?? null];
        }
        return [$route, null];
    }

    /**
     * Find the entry that matches the current request — used for active-state
     * highlighting and to render the page-head title.
     */
    public function current(): ?array
    {
        $routeName = request()->route()?->getName() ?? '';
        $group     = request()->route('group');
        foreach ($this->items as $item) {
            [$name, $param] = $this->routeParts($item);
            if (! $name) continue;
            if ($name !== $routeName) continue;
            if ($param !== null && (string) $param !== (string) $group) continue;
            return $item;
        }
        return null;
    }
}
