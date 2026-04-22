@extends('vela::layouts.admin')

@section('breadcrumb', __('Tracking'))

@section('content')
@include('vela::admin.settings._page-head', ['subtitle' => __('Google Analytics, Google Ads, Meta Pixel + Conversions API, Tag Manager.')])

@if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<style>
    .tr-card { margin-bottom: 20px; }
    .tr-card .card-header { font-weight: 600; display: flex; align-items: center; justify-content: space-between; }
    .tr-chip { font-size: 11px; padding: 2px 8px; border-radius: 999px; background: #f1f5f9; color: #475569; font-weight: 500; }
    .tr-chip-env { background: #eef2ff; color: #4338ca; }
    .tr-hint { font-size: 12px; color: #64748b; margin-top: 4px; }
    .tr-note {
        display: flex; gap: 12px; align-items: flex-start;
        padding: 12px 14px; border-radius: 8px;
        font-size: 13px; line-height: 1.5;
        margin: 12px 0 0;
    }
    .tr-note .tr-note-icon { flex-shrink: 0; font-size: 15px; padding-top: 1px; }
    .tr-note .tr-note-body { flex: 1; color: #334155; }
    .tr-note .tr-note-body strong { color: #0f172a; }
    .tr-note .tr-note-body a { color: inherit; text-decoration: underline; }
    .tr-note-info  { background: #eff6ff; border: 1px solid #bfdbfe; }
    .tr-note-info  .tr-note-icon { color: #1d4ed8; }
    .tr-note-warn  { background: #fffbeb; border: 1px solid #fde68a; }
    .tr-note-warn  .tr-note-icon { color: #b45309; }
</style>

@php
    // A queue worker is required for Meta CAPI + GA4 MP (refund) delivery.
    // Only nag when at least one of those is configured.
    $__needsQueue = !empty($values['meta_capi_access_token']) || !empty($values['ga4_api_secret']);
    $__queueDriver = (string) config('queue.default');
@endphp

@if($__needsQueue)
    <div class="tr-note tr-note-warn">
        <span class="tr-note-icon"><i class="fas fa-exclamation-triangle"></i></span>
        <div class="tr-note-body">
            <strong>Server-side events need a queue worker.</strong>
            Meta Conversions API and GA4 refund events are delivered from a
            Laravel job so checkout never blocks on Meta's or Google's latency.
            Ensure <code>php artisan queue:work</code> is running as a service
            (systemd / supervisord / Horizon) — without it, queued events sit
            in the backlog forever.
            @if($__queueDriver === 'sync')
                <br><br>
                <strong style="color:#b45309;">Current driver is <code>sync</code>.</strong>
                That means jobs run inline on the request — fine for low volume,
                but there are no retries and checkout blocks on delivery.
                Set <code>QUEUE_CONNECTION=database</code> (or <code>redis</code>)
                in <code>.env</code> and run a worker for reliability.
            @endif
        </div>
    </div>
@endif

<form method="POST" action="{{ route('vela.admin.settings.tracking.update') }}">
    @csrf

    {{-- ── Google Analytics ── --}}
    <div class="card tr-card">
        <div class="card-header">
            <span><i class="fab fa-google mr-2"></i> Google Analytics 4</span>
            @if($locked['ga_measurement_id']) <span class="tr-chip tr-chip-env">Env-managed</span> @endif
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="ga_measurement_id">Measurement ID</label>
                <input type="text" name="ga_measurement_id" id="ga_measurement_id" class="form-control"
                       placeholder="G-XXXXXXXXXX" value="{{ old('ga_measurement_id', $values['ga_measurement_id']) }}"
                       @if($locked['ga_measurement_id']) readonly @endif>
                <p class="tr-hint">The GA4 Measurement ID from <em>Admin → Data Streams</em>. Loads <code>gtag.js</code> on all public pages. Respects GDPR consent when enabled.</p>
            </div>
            <div class="form-group mb-0">
                <label for="ga4_api_secret">Measurement Protocol API secret</label>
                <input type="password" name="ga4_api_secret" id="ga4_api_secret" class="form-control"
                       placeholder="{{ $values['ga4_api_secret'] ?: '12 random chars' }}"
                       value="{{ $values['ga4_api_secret'] ? 'unchanged' : '' }}"
                       @if($locked['ga4_api_secret']) readonly @endif>
                <p class="tr-hint">Optional — required only for server-to-server events the customer's browser can't fire (e.g. <code>refund</code> after admin marks an order refunded). Generate in <em>Admin → Data Streams → Measurement Protocol API secrets</em>.</p>
            </div>
        </div>
    </div>

    {{-- ── Google Tag Manager ── --}}
    <div class="card tr-card">
        <div class="card-header">
            <span><i class="fas fa-code mr-2"></i> Google Tag Manager</span>
            @if($locked['gtm_container_id']) <span class="tr-chip tr-chip-env">Env-managed</span> @endif
        </div>
        <div class="card-body">
            <div class="form-group mb-0">
                <label for="gtm_container_id">Container ID</label>
                <input type="text" name="gtm_container_id" id="gtm_container_id" class="form-control"
                       placeholder="GTM-XXXXXXX" value="{{ old('gtm_container_id', $values['gtm_container_id']) }}"
                       @if($locked['gtm_container_id']) readonly @endif>
                <p class="tr-hint">Optional — if set, GTM loads alongside GA/Meta. All ecommerce events are pushed to <code>dataLayer</code> so GTM can forward them anywhere.</p>
            </div>
        </div>
    </div>

    {{-- ── Google Ads ── --}}
    <div class="card tr-card">
        <div class="card-header">
            <span><i class="fas fa-bullhorn mr-2"></i> Google Ads</span>
            @if($locked['google_ads_id']) <span class="tr-chip tr-chip-env">Env-managed</span> @endif
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="google_ads_id">Ads conversion ID</label>
                    <input type="text" name="google_ads_id" id="google_ads_id" class="form-control"
                           placeholder="AW-123456789" value="{{ old('google_ads_id', $values['google_ads_id']) }}"
                           @if($locked['google_ads_id']) readonly @endif>
                </div>
                <div class="form-group col-md-6">
                    <label for="google_ads_purchase_label">Purchase conversion label</label>
                    <input type="text" name="google_ads_purchase_label" id="google_ads_purchase_label" class="form-control"
                           placeholder="abcDEFghiJKL" value="{{ old('google_ads_purchase_label', $values['google_ads_purchase_label']) }}"
                           @if($locked['google_ads_purchase_label']) readonly @endif>
                </div>
            </div>
            <p class="tr-hint mb-0">On store purchase, Vela fires <code>gtag('event','conversion', { send_to: '{Ads ID}/{Label}', value, currency, transaction_id })</code>.</p>

            <div class="tr-note tr-note-info">
                <span class="tr-note-icon"><i class="fas fa-info-circle"></i></span>
                <div class="tr-note-body">
                    <strong>Enhanced Conversions for Web — enable it in Google Ads.</strong>
                    Vela automatically passes the customer's hashed identity
                    (email, phone, address) on checkout via
                    <code>gtag('set','user_data',…)</code>. For Google Ads to
                    actually use it and improve conversion measurement, you
                    must turn on <em>Enhanced Conversions for Web</em> on the
                    conversion action itself:
                    <a href="https://ads.google.com/aw/conversions" target="_blank" rel="noopener">
                        Google Ads → Tools → Conversions</a> → open your purchase
                    action → <em>Enhanced conversions</em> → toggle on → select
                    <em>Google tag</em> as the setup method. Without this step
                    the identity data is silently ignored.
                </div>
            </div>
        </div>
    </div>

    {{-- ── Meta Pixel + CAPI ── --}}
    <div class="card tr-card">
        <div class="card-header">
            <span><i class="fab fa-facebook mr-2"></i> Meta (Facebook / Instagram)</span>
            @if($locked['meta_pixel_id']) <span class="tr-chip tr-chip-env">Env-managed</span> @endif
        </div>
        <div class="card-body">
            <div class="form-group">
                <label for="meta_pixel_id">Pixel ID</label>
                <input type="text" name="meta_pixel_id" id="meta_pixel_id" class="form-control"
                       placeholder="123456789012345" value="{{ old('meta_pixel_id', $values['meta_pixel_id']) }}"
                       @if($locked['meta_pixel_id']) readonly @endif>
                <p class="tr-hint">Loads <code>fbevents.js</code>. Fires PageView on every page, plus mapped events (ViewContent, AddToCart, Purchase…) when the store is installed.</p>
            </div>

            <div class="form-group">
                <label for="meta_capi_access_token">Conversions API access token</label>
                <input type="password" name="meta_capi_access_token" id="meta_capi_access_token" class="form-control"
                       placeholder="{{ $values['meta_capi_access_token'] ?: 'EAA…' }}"
                       value="{{ $values['meta_capi_access_token'] ? 'unchanged' : '' }}"
                       @if($locked['meta_capi_access_token']) readonly @endif>
                <p class="tr-hint">Generate in <em>Events Manager → Conversions API → Access Token</em>. When set, Vela sends Purchase events server-side with the browser event_id so Meta de-duplicates.</p>
            </div>

            <div class="form-group mb-0">
                <label for="meta_capi_test_event_code">Test event code (optional)</label>
                <input type="text" name="meta_capi_test_event_code" id="meta_capi_test_event_code" class="form-control"
                       placeholder="TEST12345" value="{{ old('meta_capi_test_event_code', $values['meta_capi_test_event_code']) }}"
                       style="max-width: 280px;"
                       @if($locked['meta_capi_test_event_code']) readonly @endif>
                <p class="tr-hint">Route CAPI events to the "Test events" tab in Events Manager while you verify integration. Remove for production.</p>
            </div>

            <div class="tr-note tr-note-info">
                <span class="tr-note-icon"><i class="fas fa-info-circle"></i></span>
                <div class="tr-note-body">
                    <strong>Turn on Advanced Matching in Events Manager.</strong>
                    Vela passes hashed customer identity to
                    <code>fbq('init', ID, …)</code> on checkout success and
                    includes it on every CAPI event. For Meta to use it you
                    must enable <em>Manual Advanced Matching</em> in
                    <a href="https://business.facebook.com/events_manager2/" target="_blank" rel="noopener">Events Manager</a>
                    → your Pixel → Settings → <em>Automatic Advanced Matching</em>
                    (turn it on AND tick each parameter you want to match on:
                    email, phone, first name, last name, city, state, zip, country).
                    Without this, Meta receives the data but ignores it.
                </div>
            </div>
            <div class="tr-note tr-note-warn">
                <span class="tr-note-icon"><i class="fas fa-balance-scale"></i></span>
                <div class="tr-note-body">
                    <strong>EU / UK data-processing options.</strong>
                    If you serve EU/UK customers, open your Pixel's
                    <em>Data sources → Settings</em> and confirm the
                    <em>Data Processing Options</em> match your GDPR posture
                    (<code>LDU</code> for Limited Data Use, if applicable).
                    Vela's consent bar gates the Pixel on the new
                    <em>Marketing</em> category — make sure your privacy
                    policy describes that correctly.
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">
        <i class="fas fa-save"></i> Save tracking settings
    </button>
</form>
@endsection
