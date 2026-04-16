@extends('vela::layouts.admin')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        @include('vela::admin.tools._nav', ['toolName' => trans('vela::tools.analytics.title'), 'toolIcon' => 'fab fa-google'])
        @if($hasMeasurementId)
            <span class="badge badge-success">{{ trans('vela::tools.common.connected') }}</span>
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
                <form method="POST" action="{{ route('vela.admin.tools.google-analytics.config') }}">
                    @csrf
                    <div class="form-group">
                        <label>{{ trans('vela::tools.analytics.measurement_id') }}
                            @if($measurementIdLocked)
                                <i class="fas fa-lock text-muted ml-1" title="{{ trans('vela::tools.common.set_via_environment_variable') }}"></i>
                            @endif
                        </label>
                        <input type="text" name="ga_measurement_id"
                            class="form-control"
                            placeholder="G-XXXXXXXXXX"
                            value="{{ $measurementId }}"
                            {{ $measurementIdLocked ? 'disabled' : '' }}>
                        <small class="form-text text-muted">{{ trans('vela::tools.analytics.measurement_id_help') }}</small>
                    </div>

                    <div class="form-group">
                        <label>{{ trans('vela::tools.analytics.property_id') }}
                            @if($propertyIdLocked)
                                <i class="fas fa-lock text-muted ml-1" title="{{ trans('vela::tools.common.set_via_environment_variable') }}"></i>
                            @endif
                        </label>
                        <input type="text" name="ga_property_id"
                            class="form-control"
                            placeholder="123456789"
                            value="{{ $propertyId }}"
                            {{ $propertyIdLocked ? 'disabled' : '' }}>
                        <small class="form-text text-muted">{{ trans('vela::tools.analytics.property_id_help') }}</small>
                    </div>

                    <div class="form-group">
                        <label>{{ trans('vela::tools.analytics.service_account_key') }}
                            @if($serviceKeyLocked)
                                <i class="fas fa-lock text-muted ml-1" title="{{ trans('vela::tools.common.set_via_environment_variable') }}"></i>
                            @endif
                        </label>
                        @if($maskedServiceKey)
                            <div class="mb-1">
                                <small class="text-muted"><i class="fas fa-key"></i> {{ trans('vela::tools.analytics.current_key') }} <code>{{ $maskedServiceKey }}</code></small>
                            </div>
                        @endif
                        @if(!$serviceKeyLocked)
                            <textarea name="ga_service_account_key"
                                class="form-control"
                                rows="5"
                                placeholder='{"type":"service_account","project_id":"...","private_key":"...","client_email":"..."}'></textarea>
                            <small class="form-text text-muted">{{ trans('vela::tools.analytics.paste_json_key') }}</small>
                        @else
                            <div class="alert alert-info py-2"><i class="fas fa-lock"></i> {{ trans('vela::tools.common.managed_via_env') }}</div>
                        @endif
                    </div>

                    <button type="submit" class="btn btn-primary">{{ trans('vela::global.save_settings') }}</button>
                </form>
            </div>
        </div>
        @endif

        {{-- Tracking Status --}}
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.analytics.tracking_status') }}</h5></div>
            <div class="card-body">
                @if($hasMeasurementId)
                    <div class="d-flex align-items-center">
                        <span class="badge badge-success mr-2">{{ trans('vela::tools.common.active') }}</span>
                        <span>{{ trans('vela::tools.analytics.ga4_tag_active', ['id' => $measurementId]) }}</span>
                    </div>
                @else
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        {{ trans('vela::tools.analytics.no_measurement_id') }}
                        @if(!$canConfigure)
                            {{ trans('vela::tools.analytics.contact_admin') }}
                        @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Enhanced Measurement Status --}}
        @if($emStatus)
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.analytics.enhanced_measurement') }}</h5></div>
            <div class="card-body">
                @if($emStatus['active'] ?? false)
                    <p class="text-success mb-3"><i class="fas fa-check-circle"></i> {{ trans('vela::tools.analytics.enhanced_measurement_active') }}</p>
                    <table class="table table-sm">
                        <thead><tr><th>{{ trans('vela::tools.common.feature') }}</th><th>{{ trans('vela::tools.common.status') }}</th></tr></thead>
                        <tbody>
                            @foreach([
                                'page_views' => trans('vela::tools.analytics.page_views_feature'),
                                'scrolls' => trans('vela::tools.analytics.scroll_tracking'),
                                'outbound_clicks' => trans('vela::tools.analytics.outbound_clicks'),
                                'site_search' => trans('vela::tools.analytics.site_search'),
                                'form_interactions' => trans('vela::tools.analytics.form_interactions'),
                                'file_downloads' => trans('vela::tools.analytics.file_downloads'),
                            ] as $key => $label)
                            <tr>
                                <td>{{ $label }}</td>
                                <td>
                                    @if($emStatus[$key] ?? false)
                                        <span class="text-success"><i class="fas fa-check"></i> {{ trans('vela::tools.common.enabled') }}</span>
                                    @else
                                        <span class="text-danger"><i class="fas fa-times"></i> {{ trans('vela::tools.common.disabled') }}</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-exclamation-triangle"></i>
                        {{ $emStatus['message'] ?? trans('vela::tools.analytics.enhanced_measurement_inactive') }}
                    </div>
                @endif
            </div>
        </div>
        @endif

        {{-- Reports --}}
        @if($hasReportingAccess)
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">{{ trans('vela::tools.common.reports') }}</h5>
                <div class="btn-group btn-group-sm" role="group" id="date-range-buttons">
                    <button type="button" class="btn btn-outline-secondary range-btn" data-range="7daysAgo">{{ trans('vela::tools.common.7_days') }}</button>
                    <button type="button" class="btn btn-outline-secondary range-btn active" data-range="30daysAgo">{{ trans('vela::tools.common.30_days') }}</button>
                    <button type="button" class="btn btn-outline-secondary range-btn" data-range="90daysAgo">{{ trans('vela::tools.common.90_days') }}</button>
                </div>
            </div>
            <div class="card-body">

                <div id="reports-loading" class="text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                    <p class="text-muted mt-2">{{ trans('vela::tools.common.loading_report_data') }}</p>
                </div>

                <div id="reports-error" class="alert alert-warning d-none">
                    <i class="fas fa-exclamation-triangle"></i> {{ trans('vela::tools.analytics.reports_error') }}
                </div>

                <div id="reports-content" class="d-none">
                    {{-- Stat Cards --}}
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card bg-primary text-white">
                                <div class="card-body text-center">
                                    <div class="h2 mb-0" id="stat-sessions">—</div>
                                    <small>{{ trans('vela::tools.analytics.sessions') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-success text-white">
                                <div class="card-body text-center">
                                    <div class="h2 mb-0" id="stat-pageviews">—</div>
                                    <small>{{ trans('vela::tools.page_views') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-warning text-white">
                                <div class="card-body text-center">
                                    <div class="h2 mb-0" id="stat-bounce">—</div>
                                    <small>{{ trans('vela::tools.analytics.bounce_rate') }}</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card bg-info text-white">
                                <div class="card-body text-center">
                                    <div class="h2 mb-0" id="stat-active-users">—</div>
                                    <small>{{ trans('vela::tools.active_users') }}</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Charts --}}
                    <div class="row mb-4">
                        <div class="col-md-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header"><h6 class="mb-0">{{ trans('vela::tools.analytics.traffic_sources') }}</h6></div>
                                <div class="card-body d-flex align-items-center justify-content-center">
                                    <canvas id="chart-traffic-sources" style="max-height:250px;"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header"><h6 class="mb-0">{{ trans('vela::tools.analytics.devices') }}</h6></div>
                                <div class="card-body d-flex align-items-center justify-content-center">
                                    <canvas id="chart-devices" style="max-height:250px;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-7 mb-4">
                            <div class="card h-100">
                                <div class="card-header"><h6 class="mb-0">{{ trans('vela::tools.top_pages') }}</h6></div>
                                <div class="card-body">
                                    <canvas id="chart-top-pages" style="max-height:300px;"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5 mb-4">
                            <div class="card h-100">
                                <div class="card-header"><h6 class="mb-0">{{ trans('vela::tools.analytics.top_countries') }}</h6></div>
                                <div class="card-body">
                                    <canvas id="chart-countries" style="max-height:300px;"></canvas>
                                </div>
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
            {{ trans('vela::tools.analytics.reports_not_available_help') }}
        </div>
        @endif

    </div>
</div>
@endsection

@section('scripts')
@if($hasReportingAccess)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
(function () {
    const reportsUrl = '{{ route('vela.admin.tools.google-analytics.reports') }}';
    let charts = {};
    var msgDataCachedAt = '{{ trans('vela::tools.common.data_cached_at') }}';
    var msgPageViews = '{{ trans('vela::tools.page_views') }}';

    function formatNumber(n) {
        if (n === undefined || n === null) return '—';
        return Number(n).toLocaleString();
    }

    function getMetricValue(reportData, metricName) {
        if (!reportData || !reportData.rows || !reportData.metricHeaders) return null;
        const idx = reportData.metricHeaders.findIndex(h => h.name === metricName);
        if (idx === -1 || !reportData.rows[0]) return null;
        return reportData.rows[0].metricValues[idx]?.value ?? null;
    }

    function destroyChart(id) {
        if (charts[id]) {
            charts[id].destroy();
            delete charts[id];
        }
    }

    function renderPieChart(canvasId, labels, values, colors) {
        destroyChart(canvasId);
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;
        charts[canvasId] = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{ data: values, backgroundColor: colors }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
    }

    function renderDoughnutChart(canvasId, labels, values, colors) {
        destroyChart(canvasId);
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;
        charts[canvasId] = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: values, backgroundColor: colors }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
    }

    function renderBarChart(canvasId, labels, values, label) {
        destroyChart(canvasId);
        const ctx = document.getElementById(canvasId);
        if (!ctx) return;
        charts[canvasId] = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{ label: label, data: values, backgroundColor: 'rgba(32, 107, 196, 0.7)' }]
            },
            options: {
                responsive: true,
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true } }
            }
        });
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

            // Stat cards
            const metrics = data.metrics;
            document.getElementById('stat-sessions').textContent = formatNumber(getMetricValue(metrics, 'sessions'));
            document.getElementById('stat-pageviews').textContent = formatNumber(getMetricValue(metrics, 'screenPageViews'));
            const bounceRate = getMetricValue(metrics, 'bounceRate');
            document.getElementById('stat-bounce').textContent = bounceRate ? (parseFloat(bounceRate) * 100).toFixed(1) + '%' : '—';
            document.getElementById('stat-active-users').textContent = formatNumber(getMetricValue(metrics, 'activeUsers'));

            // Traffic sources chart
            const sources = data.traffic_sources;
            if (sources && sources.rows) {
                const labels = sources.rows.map(r => r.dimensionValues[0]?.value ?? 'Unknown');
                const values = sources.rows.map(r => parseInt(r.metricValues[0]?.value ?? 0));
                const colors = ['#4e73df','#1cc88a','#36b9cc','#f6c23e','#e74a3b','#858796','#5a5c69','#fd7e14','#6f42c1','#20c9a6'];
                renderPieChart('chart-traffic-sources', labels, values, colors.slice(0, labels.length));
            }

            // Devices chart
            const devices = data.devices;
            if (devices && devices.rows) {
                const labels = devices.rows.map(r => r.dimensionValues[0]?.value ?? 'Unknown');
                const values = devices.rows.map(r => parseInt(r.metricValues[0]?.value ?? 0));
                renderDoughnutChart('chart-devices', labels, values, ['#4e73df','#1cc88a','#36b9cc']);
            }

            // Top pages chart
            const topPages = data.top_pages;
            if (topPages && topPages.rows) {
                const labels = topPages.rows.map(r => r.dimensionValues[0]?.value ?? '/');
                const values = topPages.rows.map(r => parseInt(r.metricValues[0]?.value ?? 0));
                renderBarChart('chart-top-pages', labels, values, msgPageViews);
            }

            // Countries chart
            const countries = data.countries;
            if (countries && countries.rows) {
                const labels = countries.rows.map(r => r.dimensionValues[0]?.value ?? 'Unknown');
                const values = countries.rows.map(r => parseInt(r.metricValues[0]?.value ?? 0));
                renderBarChart('chart-countries', labels, values, '{{ trans('vela::tools.analytics.sessions') }}');
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
    loadReports('30daysAgo');
})();
</script>
@endif
@endsection
