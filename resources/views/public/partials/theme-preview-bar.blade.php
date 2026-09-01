{{-- Shown on every public page while a theme preview is running. Fixed to the
     bottom so it survives any theme's own layout, and out of the way of a
     header the visitor is there to look at. --}}
<div style="position:fixed; left:0; right:0; bottom:0; z-index:2147483000; display:flex; align-items:center; justify-content:center; gap:16px; padding:10px 16px; background:#1e1b4b; color:#fff; font:14px/1.4 system-ui, sans-serif; box-shadow:0 -2px 12px rgba(0,0,0,.25);">
    <span>{{ trans('vela::global.theme_preview_bar', ['theme' => __($themeLabel)]) }}</span>
    <a href="{{ $exitUrl }}" target="_top"
       style="background:#fff; color:#1e1b4b; padding:5px 12px; border-radius:4px; text-decoration:none; font-weight:600;">
        {{ trans('vela::global.theme_preview_exit') }}
    </a>
</div>
