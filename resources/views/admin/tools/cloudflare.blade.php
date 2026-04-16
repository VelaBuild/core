@extends('vela::layouts.admin')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        @include('vela::admin.tools._nav', ['toolName' => trans('vela::tools.cloudflare.title'), 'toolIcon' => 'fas fa-cloud'])
        @if($isConfigured)
            <span class="badge badge-success">{{ trans('vela::tools.common.configured') }}</span>
        @else
            <span class="badge badge-warning">{{ trans('vela::tools.common.not_configured') }}</span>
        @endif
    </div>
    <div class="card-body">

        @if(session('message'))
            <div class="alert alert-success">{{ session('message') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        @if($lastError)
            <div class="alert alert-danger">
                <strong>{{ trans('vela::tools.last_error') }}</strong> {{ $lastError }}
            </div>
        @endif

        {{-- Config Form --}}
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.common.configuration') }}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('vela.admin.tools.cloudflare.config') }}">
                    @csrf

                    <div class="form-group">
                        <label>{{ trans('vela::tools.cloudflare.api_token') }}
                            @if($tokenLocked)
                                <span class="badge badge-secondary ml-1"><i class="fas fa-lock"></i> {{ trans('vela::tools.common.set_via_env') }}</span>
                            @endif
                        </label>
                        <input type="password"
                               name="cf_api_token"
                               class="form-control"
                               placeholder="{{ $maskedToken ? $maskedToken : trans('vela::tools.cloudflare.enter_api_token') }}"
                               value=""
                               {{ $tokenLocked ? 'disabled' : '' }}>
                        @if($maskedToken && !$tokenLocked)
                            <small class="text-muted">{{ trans('vela::tools.cloudflare.current_token', ['token' => $maskedToken]) }}</small>
                        @endif
                    </div>

                    <div class="form-group">
                        <label>{{ trans('vela::tools.cloudflare.zone_id') }}
                            @if($zoneIdLocked)
                                <span class="badge badge-secondary ml-1"><i class="fas fa-lock"></i> {{ trans('vela::tools.common.set_via_env') }}</span>
                            @endif
                        </label>
                        <input type="text"
                               name="cf_zone_id"
                               class="form-control"
                               value="{{ $zoneIdLocked ? '' : $zoneId }}"
                               placeholder="e.g. abc123def456..."
                               {{ $zoneIdLocked ? 'disabled' : '' }}>
                        @if($zoneIdLocked && $zoneId)
                            <small class="text-muted">{{ trans('vela::tools.cloudflare.zone_id_set_via_env') }}</small>
                        @endif
                    </div>

                    <div class="form-group">
                        <label>{{ trans('vela::tools.cloudflare.purge_mode') }}</label>
                        <select name="cf_purge_mode" class="form-control">
                            <option value="smart" {{ $purgeMode === 'smart' ? 'selected' : '' }}>{{ trans('vela::tools.cloudflare.purge_mode_smart') }}</option>
                            <option value="full" {{ $purgeMode === 'full' ? 'selected' : '' }}>{{ trans('vela::tools.cloudflare.purge_mode_full') }}</option>
                        </select>
                        <small class="text-muted">{{ trans('vela::tools.cloudflare.purge_mode_help') }}</small>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save mr-1"></i> {{ trans('vela::tools.common.save_settings') }}
                    </button>
                </form>
            </div>
        </div>

        @if($isConfigured)

            {{-- Zone Status --}}
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ trans('vela::tools.cloudflare.zone_status') }}</h5>
                    <button class="btn btn-sm btn-outline-secondary" id="refresh-status-btn">
                        <i class="fas fa-sync-alt mr-1"></i> {{ trans('vela::tools.common.refresh') }}
                    </button>
                </div>
                <div class="card-body" id="zone-status-container">
                    <div class="text-muted"><i class="fas fa-spinner fa-spin mr-1"></i> {{ trans('vela::tools.cloudflare.loading_status') }}</div>
                </div>
            </div>

            {{-- Cache Purge Actions --}}
            <div class="card">
                <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.cloudflare.cache_purge') }}</h5></div>
                <div class="card-body">
                    <p class="text-muted">{{ trans('vela::tools.cloudflare.cache_purge_description') }}</p>

                    <button id="smart-purge-btn" class="btn btn-warning mr-2">
                        <i class="fas fa-broom mr-1"></i> {{ trans('vela::tools.cloudflare.smart_purge') }}
                    </button>
                    <button id="full-purge-btn" class="btn btn-danger">
                        <i class="fas fa-fire mr-1"></i> {{ trans('vela::tools.cloudflare.full_purge') }}
                    </button>

                    <div id="purge-result" class="mt-3"></div>
                </div>
            </div>

        @else
            <div class="alert alert-info">
                <i class="fas fa-info-circle mr-1"></i>
                {{ trans('vela::tools.cloudflare.not_configured_info') }}
                <br><small>{{ trans('vela::tools.cloudflare.not_configured_hint') }}</small>
            </div>
        @endif

    </div>
</div>

{{-- Full Purge Confirmation Modal --}}
<div class="modal fade" id="fullPurgeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-danger mr-1"></i> {{ trans('vela::tools.cloudflare.confirm_full_purge') }}</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>{!! trans('vela::tools.cloudflare.confirm_full_purge_body') !!}</p>
                <p class="text-muted">{{ trans('vela::tools.cloudflare.confirm_full_purge_warning') }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('vela::global.cancel') }}</button>
                <button type="button" class="btn btn-danger" id="confirm-full-purge-btn">
                    <i class="fas fa-fire mr-1"></i> {{ trans('vela::tools.cloudflare.yes_purge_everything') }}
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var msgNotConfigured = '{{ trans('vela::tools.cloudflare.not_configured_status') }}';
    var msgZoneStatus = '{{ trans('vela::tools.cloudflare.zone_status') }}';
    var msgSslMode = '{{ trans('vela::tools.cloudflare.ssl_mode') }}';
    var msgCacheLevel = '{{ trans('vela::tools.cloudflare.cache_level') }}';
    var msgDomain = '{{ trans('vela::tools.cloudflare.domain') }}';
    var msgPageRules = '{{ trans('vela::tools.cloudflare.page_rules') }}';
    var msgNoPageRules = '{{ trans('vela::tools.cloudflare.no_page_rules') }}';
    var msgFailedToLoad = '{{ trans('vela::tools.cloudflare.failed_to_load_status') }}';
    var msgCachePurged = '{{ trans('vela::tools.cloudflare.cache_purged') }}';
    var msgPurgeFailed = '{{ trans('vela::tools.cloudflare.purge_failed') }}';
    var msgRequestFailed = '{{ trans('vela::tools.common.request_failed') }}';
    var msgPurging = '{{ trans('vela::tools.cloudflare.purging') }}';
    var msgYesPurgeEverything = '{{ trans('vela::tools.cloudflare.yes_purge_everything') }}';
    var msgSmartPurge = '{{ trans('vela::tools.cloudflare.smart_purge') }}';

    function loadStatus() {
        var container = document.getElementById('zone-status-container');
        if (!container) return;

        fetch('{{ route('vela.admin.tools.cloudflare.status') }}', {
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (!data.configured) {
                container.innerHTML = '<div class="text-muted">' + msgNotConfigured + '</div>';
                return;
            }

            var zone = data.zone || {};
            var ssl = data.ssl || {};
            var cache = data.cache || {};
            var pageRules = data.page_rules || [];

            var statusBadge = zone.status === 'active'
                ? '<span class="badge badge-success">Active</span>'
                : '<span class="badge badge-danger">' + (zone.status || 'Unknown') + '</span>';

            var html = '<div class="row mb-3">'
                + '<div class="col-md-4"><strong>' + msgZoneStatus + '</strong><br>' + statusBadge + '</div>'
                + '<div class="col-md-4"><strong>' + msgSslMode + '</strong><br><span class="badge badge-info">' + (ssl.value || '—') + '</span></div>'
                + '<div class="col-md-4"><strong>' + msgCacheLevel + '</strong><br><span class="badge badge-secondary">' + (cache.value || '—') + '</span></div>'
                + '</div>';

            if (zone.name) {
                html += '<p class="text-muted mb-2"><i class="fas fa-globe mr-1"></i> <strong>' + msgDomain + '</strong> ' + zone.name + '</p>';
            }

            if (pageRules.length > 0) {
                html += '<h6 class="mt-3">' + msgPageRules + ' (' + pageRules.length + ')</h6><ul class="list-group">';
                pageRules.forEach(function (rule) {
                    var targets = (rule.targets || []).map(function (t) { return t.constraint && t.constraint.value ? t.constraint.value : ''; }).join(', ');
                    html += '<li class="list-group-item py-2">'
                        + '<span class="badge badge-' + (rule.status === 'active' ? 'success' : 'secondary') + ' mr-2">' + (rule.status || 'unknown') + '</span>'
                        + '<small>' + targets + '</small></li>';
                });
                html += '</ul>';
            } else {
                html += '<p class="text-muted mb-0">' + msgNoPageRules + '</p>';
            }

            container.innerHTML = html;
        })
        .catch(function () {
            container.innerHTML = '<div class="text-danger">' + msgFailedToLoad + '</div>';
        });
    }

    function showPurgeResult(success, message) {
        var el = document.getElementById('purge-result');
        el.innerHTML = '<div class="alert alert-' + (success ? 'success' : 'danger') + '">' + message + '</div>';
        setTimeout(function () { el.innerHTML = ''; }, 5000);
    }

    function doPurge(type) {
        var btn = type === 'full' ? document.getElementById('confirm-full-purge-btn') : document.getElementById('smart-purge-btn');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> ' + msgPurging;
        }

        fetch('{{ route('vela.admin.tools.cloudflare.purge') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ type: type })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (type === 'full') {
                $('#fullPurgeModal').modal('hide');
            }
            showPurgeResult(data.success, data.message || (data.success ? msgCachePurged : msgPurgeFailed));
        })
        .catch(function () {
            showPurgeResult(false, msgRequestFailed);
        })
        .finally(function () {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = type === 'full'
                    ? '<i class="fas fa-fire mr-1"></i> ' + msgYesPurgeEverything
                    : '<i class="fas fa-broom mr-1"></i> ' + msgSmartPurge;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        @if($isConfigured)
        loadStatus();
        @endif

        var refreshBtn = document.getElementById('refresh-status-btn');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', loadStatus);
        }

        var smartBtn = document.getElementById('smart-purge-btn');
        if (smartBtn) {
            smartBtn.addEventListener('click', function () { doPurge('smart'); });
        }

        var fullBtn = document.getElementById('full-purge-btn');
        if (fullBtn) {
            fullBtn.addEventListener('click', function () { $('#fullPurgeModal').modal('show'); });
        }

        var confirmFullBtn = document.getElementById('confirm-full-purge-btn');
        if (confirmFullBtn) {
            confirmFullBtn.addEventListener('click', function () { doPurge('full'); });
        }
    });
})();
</script>
@endsection
