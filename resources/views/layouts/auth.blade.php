<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('vendor/vela/images/vela-icon.png') }}">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            color: #1e293b;
            line-height: 1.6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .auth-wrapper {
            width: 100%;
            max-width: 420px;
        }

        .auth-brand {
            text-align: center;
            margin-bottom: 32px;
        }

        .auth-brand .auth-logo {
            height: 52px;
            width: auto;
        }

        .auth-brand h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1e293b;
            letter-spacing: -0.5px;
        }

        .auth-brand p {
            color: #64748b;
            font-size: 15px;
            margin-top: 4px;
        }

        .auth-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08), 0 8px 32px rgba(0, 0, 0, 0.06);
            padding: 36px;
        }

        /* ── Forms ── */

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }

        .form-input {
            display: block;
            width: 100%;
            padding: 10px 14px;
            font-size: 15px;
            line-height: 1.5;
            color: #1e293b;
            background: #fff;
            border: 1.5px solid #d1d5db;
            border-radius: 10px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            outline: none;
            -webkit-appearance: none;
        }

        .form-input::placeholder {
            color: #9ca3af;
        }

        .form-input:focus {
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12);
        }

        .form-input.is-invalid {
            border-color: #ef4444;
        }

        .form-input.is-invalid:focus {
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.12);
        }

        .invalid-feedback {
            color: #dc2626;
            font-size: 13px;
            margin-top: 6px;
        }

        .form-hint {
            color: #9ca3af;
            font-size: 12px;
            margin-top: 4px;
        }

        /* ── Checkbox ── */

        .form-check {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-check input[type="checkbox"] {
            width: 16px;
            height: 16px;
            border-radius: 4px;
            accent-color: #4f46e5;
            cursor: pointer;
        }

        .form-check label {
            font-size: 14px;
            color: #475569;
            cursor: pointer;
            user-select: none;
        }

        /* ── Buttons ── */

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            font-size: 15px;
            font-weight: 600;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: background-color 0.15s ease, box-shadow 0.15s ease, transform 0.1s ease;
            line-height: 1.5;
        }

        .btn:active {
            transform: scale(0.98);
        }

        .btn-primary {
            width: 100%;
            background: #4f46e5;
            color: #fff;
        }

        .btn-primary:hover {
            background: #4338ca;
        }

        .btn-primary:focus-visible {
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.3);
            outline: none;
        }

        .btn-secondary {
            background: #f1f5f9;
            color: #475569;
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        .btn-danger {
            background: #fee2e2;
            color: #dc2626;
        }

        .btn-danger:hover {
            background: #fecaca;
        }

        .btn-link {
            background: none;
            border: none;
            color: #4f46e5;
            font-weight: 500;
            padding: 0;
            font-size: 14px;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-link:hover {
            color: #4338ca;
            text-decoration: underline;
        }

        /* ── Layout helpers ── */

        .auth-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
            flex-wrap: wrap;
            gap: 8px;
        }

        .auth-footer-center {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #f1f5f9;
        }

        .auth-divider {
            border: none;
            border-top: 1px solid #e5e7eb;
            margin: 24px 0;
        }

        /* ── Alerts ── */

        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .alert-info {
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* ── SVG Icons (inline in forms) ── */

        .auth-icon {
            display: inline-block;
            width: 20px;
            height: 20px;
            vertical-align: middle;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        /* ── Responsive ── */

        @media (max-width: 480px) {
            body { padding: 16px; }
            .auth-card { padding: 24px; border-radius: 12px; }
            .auth-brand h1 { font-size: 24px; }
            .auth-footer { flex-direction: column; align-items: stretch; text-align: center; }
        }
    </style>
    @yield('styles')
</head>
<body>
    <div class="auth-wrapper">
        <div class="auth-brand">
            <img src="{{ asset('vendor/vela/images/vela-logo-black.png') }}" alt="{{ config('app.name') }}" class="auth-logo">
            @yield('subtitle')
        </div>
        <div class="auth-card">
            @yield('content')
        </div>
    </div>
    @yield('scripts')
</body>
</html>
