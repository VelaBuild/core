<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('vela::pwa.offline_title') }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            background: #f3f4f6;
            color: #1f2937;
        }
        .offline-container { text-align: center; padding: 2rem; }
        .offline-icon { font-size: 4rem; margin-bottom: 1rem; }
        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        p { color: #6b7280; margin-bottom: 1.5rem; }
        button {
            background: #1f2937; color: white; border: none;
            padding: 0.75rem 1.5rem; border-radius: 0.5rem;
            cursor: pointer; font-size: 1rem;
        }
        button:hover { background: #374151; }
    </style>
</head>
<body>
    <div class="offline-container">
        <div class="offline-icon">&#128268;</div>
        <h1>{{ __('vela::pwa.offline_heading') }}</h1>
        <p>{{ __('vela::pwa.offline_message') }}</p>
        <button onclick="window.location.reload()">{{ __('vela::pwa.try_again') }}</button>
    </div>
</body>
</html>
