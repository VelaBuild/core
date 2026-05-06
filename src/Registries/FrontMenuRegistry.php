<?php

namespace VelaBuild\Core\Registries;

/**
 * Tracks the menu *slots* a theme declares for its public layout, e.g.
 *   primary, footer_quick_links, footer_legal
 *
 * Slots are populated from each registered template's `template.json`
 * (key: "menus") in `Vela::registerDefaultTemplates()`. Hosts can also
 * call `Vela::frontMenus()->registerSlot(...)` from an app provider.
 */
class FrontMenuRegistry
{
    /** @var array<string, array> */
    protected array $slots = [];

    public function registerSlot(string $key, array $config = []): void
    {
        $this->slots[$key] = array_merge([
            'label'          => ucwords(str_replace(['-', '_'], ' ', $key)),
            'description'    => '',
            'auto_add_pages' => $key === 'primary',
            'template'       => null,
        ], $this->slots[$key] ?? [], $config);
    }

    public function get(string $key): ?array
    {
        return $this->slots[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->slots[$key]);
    }

    /** @return array<string, array> */
    public function all(): array
    {
        return $this->slots;
    }

    /**
     * Slots declared by the currently active template — what the admin UI
     * should show. If a slot has been populated by the user but is no longer
     * declared by the active theme, it is still returned (with an
     * `orphaned => true` flag) so users can clean it up.
     */
    public function forActiveTemplate(?string $template = null): array
    {
        $template = $template ?: config('vela.template.active');
        $declared = [];
        foreach ($this->slots as $key => $slot) {
            if ($slot['template'] === null || $slot['template'] === $template) {
                $declared[$key] = $slot;
            }
        }
        return $declared;
    }
}
