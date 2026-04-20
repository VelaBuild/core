@if(vela_config('pwa_enabled', '1') !== '0')
    <link rel="manifest" href="{{ app()->getLocale() === config('app.locale') ? url('/manifest.json') : url(app()->getLocale() . '/manifest.json') }}">
@if(file_exists(public_path('storage/pwa-icons/icon-192x192.png')))
    <link rel="apple-touch-icon" href="{{ asset('storage/pwa-icons/icon-192x192.png') }}">
@endif
@endif
