@php
    $settingsItems = [
        ['key' => 'general',       'label' => __('vela::pwa.settings_general'),      'icon' => 'fas fa-globe',        'route' => ['vela.admin.settings.group', 'general']],
        ['key' => 'appearance',    'label' => __('vela::pwa.settings_appearance'),   'icon' => 'fas fa-palette',      'route' => ['vela.admin.settings.group', 'appearance']],
        ['key' => 'customcss',     'label' => __('vela::global.custom_css_js'),      'icon' => 'fas fa-code',         'route' => ['vela.admin.settings.group', 'customcss']],
        ['key' => 'pwa',           'label' => __('vela::pwa.settings_pwa'),          'icon' => 'fas fa-mobile-alt',   'route' => ['vela.admin.settings.group', 'pwa']],
        ['key' => 'app',           'label' => __('vela::pwa.settings_app'),          'icon' => 'fas fa-tablet-alt',   'route' => ['vela.admin.settings.group', 'app']],
        ['key' => 'visibility',    'label' => __('vela::visibility.settings_title'), 'icon' => 'fas fa-eye',          'route' => ['vela.admin.settings.group', 'visibility']],
        ['key' => 'gdpr',          'label' => __('vela::gdpr.settings_title'),       'icon' => 'fas fa-shield-alt',   'route' => ['vela.admin.settings.group', 'gdpr']],
        ['key' => 'languages',     'label' => __('vela::global.languages'),          'icon' => 'fas fa-language',     'route' => ['vela.admin.settings.group', 'languages']],
        ['key' => 'mcp',           'label' => __('vela::mcp.settings_title'),        'icon' => 'fas fa-plug',         'route' => ['vela.admin.settings.group', 'mcp']],
        // Standalone core settings pages — not under settings.group().
        ['key' => 'tracking',      'label' => __('Tracking'),                        'icon' => 'fas fa-bullseye',     'route' => ['vela.admin.settings.tracking.index']],
        ['key' => 'design-system', 'label' => __('Design System'),                   'icon' => 'fas fa-swatchbook',   'route' => ['vela.admin.settings.design-system.index']],
    ];

    // Plugin-provided settings pages (only shown when the plugin is loaded).
    if (\Illuminate\Support\Facades\Route::has('vela.admin.store.settings.index')) {
        $settingsItems[] = ['key' => 'store', 'label' => __('Store'), 'icon' => 'fas fa-store', 'route' => ['vela.admin.store.settings.index']];
    }

    // Active-item detection: match by route name first (standalone pages),
    // fall back to the `group` param match for the settings.group.* routes.
    $currentRouteName = request()->route()?->getName() ?? '';
    $currentGroup     = request()->route('group') ?? '';
    $current = null;
    foreach ($settingsItems as $item) {
        [$name, $param] = array_pad($item['route'], 2, null);
        if ($name === $currentRouteName && ($param === null || $param === $currentGroup)) {
            $current = $item;
            break;
        }
    }
@endphp
<div class="dropdown d-inline-block">
    <button class="btn btn-link text-dark font-weight-bold p-0 dropdown-toggle" type="button" id="settingsNavDropdown" data-toggle="dropdown" data-coreui-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="font-size:1.25rem; text-decoration:none;">
        <i class="{{ $current['icon'] ?? 'fas fa-cog' }} mr-1"></i> {{ $current['label'] ?? trans('vela::cruds.config.title') }}
    </button>
    <div class="dropdown-menu" aria-labelledby="settingsNavDropdown">
        <a class="dropdown-item" href="{{ route('vela.admin.settings.index') }}">
            <i class="fas fa-th-large mr-2"></i> {{ trans('vela::cruds.config.title') }}
        </a>
        <div class="dropdown-divider"></div>
        @foreach($settingsItems as $item)
            @can('config_access')
                @php [$__name, $__param] = array_pad($item['route'], 2, null); @endphp
                <a class="dropdown-item {{ ($current['key'] ?? null) === $item['key'] ? 'active' : '' }}"
                   href="{{ $__param !== null ? route($__name, $__param) : route($__name) }}">
                    <i class="{{ $item['icon'] }} mr-2"></i> {{ $item['label'] }}
                </a>
            @endcan
        @endforeach
    </div>
</div>
