@if(\VelaBuild\Core\Models\VelaConfig::where('key', 'pwa_enabled')->value('value') !== '0')
    <link rel="manifest" href="{{ app()->getLocale() === config('app.locale') ? url('/manifest.json') : url(app()->getLocale() . '/manifest.json') }}">
    <link rel="apple-touch-icon" href="{{ asset('storage/pwa-icons/icon-192x192.png') }}">
@endif
