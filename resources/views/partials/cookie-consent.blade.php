{{--
    Cookie Consent Banner — only rendered when config('vela.gdpr.enabled') is true.
    Include with: @include('vela::partials.cookie-consent')

    Consent is stored in a first-party cookie (default: vela_consent).
    When analytics consent is granted, the event 'vela:consent:analytics' is
    dispatched on `document` so scripts can react.
--}}
@if(config('vela.gdpr.enabled'))
@php
    $cookieName = config('vela.gdpr.cookie_name', 'vela_consent');
    $privacyUrl = config('vela.gdpr.privacy_url', '/privacy');
    $cookieDays = config('vela.gdpr.cookie_lifetime', 365);
@endphp
<div id="vela-consent" style="display:none">
    <div class="vc-bar" role="dialog" aria-label="Cookie consent">
        <div class="vc-inner">
            <div class="vc-text">
                <p>{{ __('vela::gdpr.banner_text') }}</p>
            </div>
            <div class="vc-actions">
                <button type="button" class="vc-btn vc-btn-accept" id="vc-accept-all">{{ __('vela::gdpr.accept_all') }}</button>
                <button type="button" class="vc-btn vc-btn-necessary" id="vc-necessary-only">{{ __('vela::gdpr.necessary_only') }}</button>
                <button type="button" class="vc-btn vc-btn-manage" id="vc-manage-toggle">{{ __('vela::gdpr.manage') }}</button>
                <a href="{{ url($privacyUrl) }}" class="vc-privacy-link">{{ __('vela::gdpr.privacy_link') }}</a>
            </div>

            {{-- Expandable category panel --}}
            <div class="vc-categories" id="vc-categories" style="display:none">
                <div class="vc-category">
                    <div class="vc-category-header">
                        <label class="vc-switch vc-switch-locked">
                            <input type="checkbox" checked disabled>
                            <span class="vc-slider"></span>
                        </label>
                        <div>
                            <strong>{{ __('vela::gdpr.cat_necessary') }}</strong>
                            <p>{{ __('vela::gdpr.cat_necessary_desc') }}</p>
                        </div>
                    </div>
                </div>

                <div class="vc-category">
                    <div class="vc-category-header">
                        <label class="vc-switch">
                            <input type="checkbox" id="vc-cat-functional" checked>
                            <span class="vc-slider"></span>
                        </label>
                        <div>
                            <strong>{{ __('vela::gdpr.cat_functional') }}</strong>
                            <p>{{ __('vela::gdpr.cat_functional_desc') }}</p>
                        </div>
                    </div>
                </div>

                <div class="vc-category">
                    <div class="vc-category-header">
                        <label class="vc-switch">
                            <input type="checkbox" id="vc-cat-analytics">
                            <span class="vc-slider"></span>
                        </label>
                        <div>
                            <strong>{{ __('vela::gdpr.cat_analytics') }}</strong>
                            <p>{{ __('vela::gdpr.cat_analytics_desc') }}</p>
                        </div>
                    </div>
                </div>

                <div class="vc-category-actions">
                    <button type="button" class="vc-btn vc-btn-accept" id="vc-save-prefs">{{ __('vela::gdpr.save') }}</button>
                </div>
            </div>

        </div>
    </div>
</div>

@push('head')
<style>
    /* ── Consent bar (no overlay — site remains interactive) ── */
    #vela-consent { position: fixed; bottom: 0; left: 0; right: 0; z-index: 99999; pointer-events: none; }
    #vela-consent.vc-visible { pointer-events: auto; }

    .vc-bar {
        background: #fff;
        box-shadow: 0 -4px 24px rgba(0,0,0,.12);
        transform: translateY(100%);
        transition: transform .35s cubic-bezier(.4,0,.2,1);
        max-height: 90vh; overflow-y: auto;
    }
    #vela-consent.vc-visible .vc-bar { transform: translateY(0); }

    .vc-inner { max-width: 720px; margin: 0 auto; padding: 24px 24px 20px; }

    .vc-text p {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        font-size: 14px; line-height: 1.6; color: #374151; margin: 0;
    }

    /* ── Buttons ── */
    .vc-actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; align-items: center; }
    .vc-btn {
        font-family: inherit; font-size: 14px; font-weight: 600;
        padding: 10px 20px; border-radius: 8px; border: none;
        cursor: pointer; transition: background .15s ease, transform .1s ease;
        white-space: nowrap;
    }
    .vc-btn:active { transform: scale(.97); }
    .vc-btn-accept { background: #4f46e5; color: #fff; }
    .vc-btn-accept:hover { background: #4338ca; }
    .vc-btn-necessary { background: #f1f5f9; color: #475569; }
    .vc-btn-necessary:hover { background: #e2e8f0; }
    .vc-btn-manage { background: transparent; color: #4f46e5; padding: 10px 12px; }
    .vc-btn-manage:hover { background: #f1f5f9; }

    /* ── Category panel ── */
    .vc-categories { margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 16px; }
    .vc-category { padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
    .vc-category:last-of-type { border-bottom: none; }
    .vc-category-header { display: flex; gap: 14px; align-items: flex-start; }
    .vc-category-header strong { font-size: 14px; color: #1e293b; display: block; }
    .vc-category-header p { font-size: 13px; color: #64748b; margin: 2px 0 0; line-height: 1.4; }
    .vc-category-actions { padding-top: 12px; }

    /* ── Toggle switch ── */
    .vc-switch { position: relative; display: inline-block; width: 40px; min-width: 40px; height: 22px; margin-top: 2px; }
    .vc-switch input { opacity: 0; width: 0; height: 0; }
    .vc-slider {
        position: absolute; cursor: pointer; inset: 0;
        background: #d1d5db; border-radius: 22px; transition: background .2s ease;
    }
    .vc-slider::before {
        content: ''; position: absolute; height: 16px; width: 16px;
        left: 3px; bottom: 3px; background: #fff; border-radius: 50%;
        transition: transform .2s ease;
    }
    .vc-switch input:checked + .vc-slider { background: #4f46e5; }
    .vc-switch input:checked + .vc-slider::before { transform: translateX(18px); }
    .vc-switch-locked .vc-slider { cursor: not-allowed; opacity: .6; }
    .vc-switch-locked input:checked + .vc-slider { background: #22c55e; opacity: .7; }

    /* ── Privacy link (inline with buttons, right-aligned) ── */
    .vc-privacy-link { margin-left: auto; font-size: 13px; color: #6b7280; text-decoration: underline; white-space: nowrap; }
    .vc-privacy-link:hover { color: #4f46e5; }

    /* ── Responsive ── */
    @media (max-width: 600px) {
        .vc-inner { padding: 20px 16px 16px; }
        .vc-actions { flex-direction: column; }
        .vc-btn { width: 100%; text-align: center; }
        .vc-privacy-link { margin-left: 0; text-align: center; }
    }
</style>
@endpush

<script>
(function() {
    var COOKIE = @json($cookieName);
    var DAYS   = {{ $cookieDays }};
    var el     = document.getElementById('vela-consent');
    if (!el) return;

    /* ── Cookie helpers ── */
    function getCookie(name) {
        var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + '=([^;]*)'));
        return m ? decodeURIComponent(m[1]) : null;
    }
    function setCookie(name, value, days) {
        var d = new Date(); d.setTime(d.getTime() + days * 864e5);
        document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    }

    /* ── Parse stored consent ── */
    function parse(val) {
        if (!val) return null;
        try { return JSON.parse(val); } catch(e) { return null; }
    }

    /* ── Dispatch events so other scripts can react ── */
    function fireEvents(consent) {
        if (consent.analytics) document.dispatchEvent(new CustomEvent('vela:consent:analytics'));
        if (consent.functional) document.dispatchEvent(new CustomEvent('vela:consent:functional'));
    }

    /* ── Check existing consent ── */
    var existing = parse(getCookie(COOKIE));
    if (existing) {
        fireEvents(existing);
        return; /* banner stays hidden */
    }

    /* ── Show banner ── */
    el.style.display = '';
    requestAnimationFrame(function() { el.classList.add('vc-visible'); });

    function save(consent) {
        setCookie(COOKIE, JSON.stringify(consent), DAYS);
        el.classList.remove('vc-visible');
        setTimeout(function() { el.style.display = 'none'; }, 400);
        fireEvents(consent);
    }

    document.getElementById('vc-accept-all').addEventListener('click', function() {
        save({ necessary: true, functional: true, analytics: true });
    });

    document.getElementById('vc-necessary-only').addEventListener('click', function() {
        save({ necessary: true, functional: false, analytics: false });
    });

    var categoriesEl = document.getElementById('vc-categories');
    document.getElementById('vc-manage-toggle').addEventListener('click', function() {
        categoriesEl.style.display = categoriesEl.style.display === 'none' ? '' : 'none';
    });

    document.getElementById('vc-save-prefs').addEventListener('click', function() {
        save({
            necessary:  true,
            functional: document.getElementById('vc-cat-functional').checked,
            analytics:  document.getElementById('vc-cat-analytics').checked
        });
    });
})();
</script>
@endif
