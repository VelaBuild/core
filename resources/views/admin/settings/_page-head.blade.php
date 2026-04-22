{{-- Shared page-head for every Settings sub-page.

     Renders:
       • Breadcrumb   → "Settings / {current label}"
       • Title row    → _nav dropdown (acts as H1 + switcher) + optional subtitle
       • Back button  → to the main Settings index

     Usage:
         @include('vela::admin.settings._page-head')
         @include('vela::admin.settings._page-head', ['subtitle' => '...'])

     Active-item detection mirrors _nav.blade.php — kept in sync by hand;
     extract to a shared PHP file if a third consumer appears. --}}
@php
    $__items = [
        ['key' => 'general',       'label' => __('vela::pwa.settings_general'),      'route' => ['vela.admin.settings.group', 'general']],
        ['key' => 'appearance',    'label' => __('vela::pwa.settings_appearance'),   'route' => ['vela.admin.settings.group', 'appearance']],
        ['key' => 'customcss',     'label' => __('vela::global.custom_css_js'),      'route' => ['vela.admin.settings.group', 'customcss']],
        ['key' => 'pwa',           'label' => __('vela::pwa.settings_pwa'),          'route' => ['vela.admin.settings.group', 'pwa']],
        ['key' => 'app',           'label' => __('vela::pwa.settings_app'),          'route' => ['vela.admin.settings.group', 'app']],
        ['key' => 'visibility',    'label' => __('vela::visibility.settings_title'), 'route' => ['vela.admin.settings.group', 'visibility']],
        ['key' => 'gdpr',          'label' => __('vela::gdpr.settings_title'),       'route' => ['vela.admin.settings.group', 'gdpr']],
        ['key' => 'languages',     'label' => __('vela::global.languages'),          'route' => ['vela.admin.settings.group', 'languages']],
        ['key' => 'mcp',           'label' => __('vela::mcp.settings_title'),        'route' => ['vela.admin.settings.group', 'mcp']],
        ['key' => 'tracking',      'label' => __('Tracking'),                        'route' => ['vela.admin.settings.tracking.index']],
        ['key' => 'design-system', 'label' => __('Design System'),                   'route' => ['vela.admin.settings.design-system.index']],
    ];
    if (\Illuminate\Support\Facades\Route::has('vela.admin.store.settings.index')) {
        $__items[] = ['key' => 'store', 'label' => __('Store'), 'route' => ['vela.admin.store.settings.index']];
    }

    $__routeName = request()->route()?->getName() ?? '';
    $__group     = request()->route('group') ?? '';
    $__currentLabel = trans('vela::cruds.config.title');
    foreach ($__items as $__it) {
        [$__n, $__p] = array_pad($__it['route'], 2, null);
        if ($__n === $__routeName && ($__p === null || $__p === $__group)) {
            $__currentLabel = $__it['label'];
            break;
        }
    }
@endphp
<div class="vela-page-head">
    <div class="vela-page-head-left">
        <div class="vela-breadcrumb">
            <a href="{{ route('vela.admin.settings.index') }}">{{ __('Settings') }}</a>
            / <span class="cur">{{ $__currentLabel }}</span>
        </div>
        <div class="vela-page-title-row">
            <div>
                @include('vela::admin.settings._nav')
                @isset($subtitle)
                    <p class="vela-page-sub">{{ $subtitle }}</p>
                @endisset
            </div>
        </div>
    </div>
    <div class="vela-page-actions">
        <a class="btn btn-secondary" href="{{ route('vela.admin.settings.index') }}">{{ __('Back') }}</a>
    </div>
</div>
