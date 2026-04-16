@extends('vela::layouts.admin')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        @include('vela::admin.tools._nav', ['toolName' => trans('vela::tools.search_console.title'), 'toolIcon' => 'fab fa-google'])
        @if($hasReportingAccess ?? false)
            <span class="badge badge-success">{{ trans('vela::tools.common.connected') }}</span>
        @elseif(isset($siteUrl) && $siteUrl)
            <span class="badge badge-info">{{ trans('vela::tools.common.configured') }}</span>
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

        {{-- Config Form --}}
        @if($canConfigure)
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.common.configuration') }}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('vela.admin.tools.search-console.config') }}">
                    @csrf
                    <div class="form-group">
                        <label>{{ trans('vela::tools.search_console.site_url') }}
                            @if($siteUrlLocked ?? false)
                                <i class="fas fa-lock text-muted ml-1" title="{{ trans('vela::tools.common.set_via_environment_variable') }}"></i>
                            @endif
                        </label>
                        <input type="text" name="gsc_site_url"
                            class="form-control"
                            placeholder="https://example.com"
                            value="{{ $siteUrl ?? '' }}"
                            {{ ($siteUrlLocked ?? false) ? 'disabled' : '' }}>
                        <small class="form-text text-muted">{{ trans('vela::tools.search_console.site_url_help') }}</small>
                    </div>

                    <div class="form-group mb-0">
                        <label>{{ trans('vela::tools.search_console.service_account_key') }}</label>
                        @if($maskedServiceKey ?? false)
                            <div class="mb-1">
                                <small class="text-muted"><i class="fas fa-key"></i> {{ trans('vela::tools.search_console.shared_with_analytics') }} <code>{{ $maskedServiceKey }}</code></small>
                            </div>
                            <div class="alert alert-info py-2 mb-0">
                                <i class="fas fa-link"></i> {{ trans('vela::tools.search_console.shared_key_info') }} <a href="{{ route('vela.admin.tools.google-analytics') }}">{{ trans('vela::tools.search_console.google_analytics_link') }}</a> {{ trans('vela::tools.search_console.tool_label') }}
                            </div>
                        @else
                            <div class="alert alert-warning py-2 mb-0">
                                <i class="fas fa-exclamation-triangle"></i> {{ trans('vela::tools.search_console.no_service_key_warning') }} <a href="{{ route('vela.admin.tools.google-analytics') }}">{{ trans('vela::tools.search_console.google_analytics_link') }}</a> {{ trans('vela::tools.search_console.no_service_key_suffix') }}
                            </div>
                        @endif
                    </div>

                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">{{ trans('vela::global.save_settings') }}</button>
                    </div>
                </form>
            </div>
        </div>
        @endif

        {{-- Site Verification --}}
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.search_console.site_verification') }}</h5></div>
            <div class="card-body">
                <p class="text-muted mb-3">{!! trans('vela::tools.search_console.site_verification_help') !!}</p>
                <div class="form-group">
                    <label>{{ trans('vela::tools.search_console.html_meta_tag_verification') }}</label>
                    <div class="input-group">
                        <input type="text" class="form-control font-monospace"
                            id="verification-meta"
                            value='&lt;meta name="google-site-verification" content="YOUR_VERIFICATION_CODE" /&gt;'
                            readonly>
                        <div class="input-group-append">
                            <button class="btn btn-outline-secondary" type="button" id="copy-meta-btn">
                                <i class="fas fa-copy"></i> {{ trans('vela::tools.common.copy') }}
                            </button>
                        </div>
                    </div>
                    <small class="form-text text-muted">{{ trans('vela::tools.replace_verification_code') }}</small>
                </div>
            </div>
        </div>

        {{-- Reports --}}
        @if($hasReportingAccess ?? false)
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">{{ trans('vela::tools.search_console.search_performance') }}</h5>
                <div class="btn-group btn-group-sm" role="group" id="date-range-buttons">
                    <button type="button" class="btn btn-outline-secondary range-btn" data-range="7daysAgo">{{ trans('vela::tools.common.7_days') }}</button>
                    <button type="button" class="btn btn-outline-secondary range-btn active" data-range="28daysAgo">{{ trans('vela::tools.common.28_days') }}</button>
                    <button type="button" class="btn btn-outline-secondary range-btn" data-range="90daysAgo">{{ trans('vela::tools.common.90_days') }}</button>
                </div>
            </div>
            <div class="card-body">

                <div id="reports-loading" class="text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                    <p class="text-muted mt-2">{{ trans('vela::tools.common.loading_report_data') }}</p>
                </div>

                <div id="reports-error" class="alert alert-warning d-none">
                    <i class="fas fa-exclamation-triangle"></i> {{ trans('vela::tools.search_console.reports_error') }}
                </div>

                <div id="reports-content" class="d-none">
                    {{-- Stat Cards --}}
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card bg-primary text-white">
                                <div class="card-body text-center">
                                    <div class="h2 mb-0" id="stat-clicks">—</div>
                                    <small>{{ trans('vela::tools.search_console.total_clicks') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-info text-white">
                                <div class="card-body text-center">
                                    <div class="h2 mb-0" id="stat-impressions">—</div>
                                    <small>{{ trans('vela::tools.search_console.impressions') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <div class="h2 mb-0" id="stat-ctr">—</div>
                                    <small>{{ trans('vela::tools.search_console.avg_ctr') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-warning text-white">
                                <div class="card-body text-center">
                                    <div class="h2 mb-0" id="stat-position">—</div>
                                    <small>{{ trans('vela::tools.search_console.avg_position') }}</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Charts --}}
                    <div class="row mb-4">
                        <div class="col-md-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header"><h6 class="mb-0">{{ trans('vela::tools.search_console.top_queries_by_clicks') }}</h6></div>
                                <div class="card-body">
                                    <canvas id="chart-queries" style="max-height:300px;"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header"><h6 class="mb-0">{{ trans('vela::tools.top_pages_by_clicks') }}</h6></div>
                                <div class="card-body">
                                    <canvas id="chart-pages" style="max-height:300px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Top Queries Table --}}
                    <div class="card mb-4">
                        <div class="card-header"><h6 class="mb-0">{{ trans('vela::tools.search_console.top_queries') }}</h6></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>{{ trans('vela::tools.search_console.query') }}</th>
                                            <th class="text-right">{{ trans('vela::tools.search_console.clicks') }}</th>
                                            <th class="text-right">{{ trans('vela::tools.search_console.impressions') }}</th>
                                            <th class="text-right">{{ trans('vela::tools.search_console.ctr') }}</th>
                                            <th class="text-right">{{ trans('vela::tools.search_console.avg_position') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody id="queries-table-body">
                                        <tr><td colspan="5" class="text-center text-muted py-3">{{ trans('vela::tools.common.loading') }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {{-- Top Pages Table --}}
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0">{{ trans('vela::tools.top_pages') }}</h6></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>{{ trans('vela::tools.search_console.page') }}</th>
                                            <th class="text-right">{{ trans('vela::tools.search_console.clicks') }}</th>
                                            <th class="text-right">{{ trans('vela::tools.search_console.impressions') }}</th>
                                            <th class="text-right">{{ trans('vela::tools.search_console.ctr') }}</th>
                                            <th class="text-right">{{ trans('vela::tools.search_console.avg_position') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody id="pages-table-body">
                                        <tr><td colspan="5" class="text-center text-muted py-3">{{ trans('vela::tools.common.loading') }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
            <div class="card-footer text-muted">
                <small id="reports-cached-at"></small>
            </div>
        </div>
        @else
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <strong>{{ trans('vela::tools.common.reports_not_available') }}</strong>
            {{ trans('vela::tools.search_console.reports_not_available_help') }}
        </div>
        @endif

    </div>
</div>
@endsection

@section('scripts')
@if($hasReportingAccess ?? false)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
@endif
<script>
// Copy meta tag
document.getElementById('copy-meta-btn')?.addEventListener('click', function () {
    const input = document.getElementById('verification-meta');
    input.select();
    document.execCommand('copy');
    this.innerHTML = '<i class="fas fa-check"></i> {{ trans('vela::tools.common.copied') }}';
    var self = this;
    setTimeout(function () { self.innerHTML = '<i class="fas fa-copy"></i> {{ trans('vela::tools.common.copy') }}'; }, 2000);
});
</script>
@if($hasReportingAccess ?? false)
<script>
(function () {
    const reportsUrl = '{{ route('vela.admin.tools.search-console.reports') }}';
    let charts = {};
    var msgClicks = '{{ trans('vela::tools.search_console.clicks') }}';
    var msgNoDataAvailable = '{{ trans('vela::tools.common.no_data_available') }}';
    var msgDataCachedAt = '{{ trans('vela::tools.common.data_cached_at') }}';

    function formatNumber(n) {
        if (n === undefined || n === null) return '—';
        return Number(n).toLocaleString();
    }

    function formatPercent(n) {
        if (n === undefined || n === null) return '—';
        return (parseFloat(n) * 100).toFixed(1) + '%';
    }

    function formatPosition(n) {
        if (n === undefined || n === null) return '—';
        return parseFloat(n).toFixed(1);
    }

    function destroyChart(id) {
        if (charts[id]) {
            charts[id].destroy();
            delete charts[id];
        }
    }

    function renderBarChart(canvasId, labels, values, label, color) {
        destroyChart(canvasId);
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;
        charts[canvasId] = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{ label: label, data: values, backgroundColor: color || 'rgba(32, 107, 196, 0.7)' }]
            },
            options: {
                responsive: true,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true } }
            }
        });
    }

    function renderTable(tbodyId, rows) {
        const tbody = document.getElementById(tbodyId);
        if (!tbody) return;
        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">' + msgNoDataAvailable + '</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(row => {
            const keys = row.keys || [];
            const label = keys[0] || '—';
            return `<tr>
                <td class="text-truncate" style="max-width:300px;" title="${escapeHtml(label)}">${escapeHtml(label)}</td>
                <td class="text-right">${formatNumber(row.clicks)}</td>
                <td class="text-right">${formatNumber(row.impressions)}</td>
                <td class="text-right">${formatPercent(row.ctr)}</td>
                <td class="text-right">${formatPosition(row.position)}</td>
            </tr>`;
        }).join('');
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function loadReports(range) {
        document.getElementById('reports-loading').classList.remove('d-none');
        document.getElementById('reports-content').classList.add('d-none');
        document.getElementById('reports-error').classList.add('d-none');

        fetch(reportsUrl + '?range=' + encodeURIComponent(range), {
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content') }
        })
        .then(res => res.json())
        .then(json => {
            document.getElementById('reports-loading').classList.add('d-none');

            const data = json.data;
            if (!data) {
                document.getElementById('reports-error').classList.remove('d-none');
                return;
            }

            document.getElementById('reports-content').classList.remove('d-none');

            // Stat cards from totals
            const totals = data.totals;
            if (totals && totals.rows && totals.rows[0]) {
                const row = totals.rows[0];
                document.getElementById('stat-clicks').textContent = formatNumber(row.clicks);
                document.getElementById('stat-impressions').textContent = formatNumber(row.impressions);
                document.getElementById('stat-ctr').textContent = formatPercent(row.ctr);
                document.getElementById('stat-position').textContent = formatPosition(row.position);
            }

            // Top queries chart
            const queries = data.queries;
            if (queries && queries.rows) {
                const labels = queries.rows.slice(0, 10).map(r => (r.keys || ['?'])[0]);
                const values = queries.rows.slice(0, 10).map(r => r.clicks || 0);
                renderBarChart('chart-queries', labels, values, msgClicks, 'rgba(32, 107, 196, 0.7)');
                renderTable('queries-table-body', queries.rows);
            }

            // Top pages chart
            const pages = data.pages;
            if (pages && pages.rows) {
                const labels = pages.rows.slice(0, 10).map(r => {
                    const url = (r.keys || [''])[0];
                    try { return new URL(url).pathname; } catch (e) { return url; }
                });
                const values = pages.rows.slice(0, 10).map(r => r.clicks || 0);
                renderBarChart('chart-pages', labels, values, msgClicks, 'rgba(28, 200, 138, 0.7)');
                renderTable('pages-table-body', pages.rows);
            }

            if (json.cached_at) {
                document.getElementById('reports-cached-at').textContent = msgDataCachedAt + ' ' + json.cached_at;
            }
        })
        .catch(() => {
            document.getElementById('reports-loading').classList.add('d-none');
            document.getElementById('reports-error').classList.remove('d-none');
        });
    }

    // Range toggle
    document.querySelectorAll('.range-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            loadReports(this.dataset.range);
        });
    });

    // Initial load
    loadReports('28daysAgo');
})();
</script>
@endif
@endsection
