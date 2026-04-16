@extends('vela::layouts.admin')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        @include('vela::admin.tools._nav', ['toolName' => trans('vela::tools.pagespeed.title'), 'toolIcon' => 'fas fa-tachometer-alt'])
        <span class="badge badge-success">{{ trans('vela::tools.common.ready') }}</span>
    </div>
    <div class="card-body">

        @if(session('message'))
            <div class="alert alert-success">{{ session('message') }}</div>
        @endif

        {{-- Scan Form --}}
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.pagespeed.run_a_scan') }}</h5></div>
            <div class="card-body">
                <div class="form-group">
                    <label>{{ trans('vela::tools.pagespeed.url_to_scan') }}</label>
                    <select id="scan-url-select" class="form-control">
                        <option value="">{{ trans('vela::tools.pagespeed.select_url_or_type') }}</option>
                        @foreach($urls as $url)
                            <option value="{{ $url }}">{{ $url }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>{{ trans('vela::tools.pagespeed.or_enter_custom_url') }}</label>
                    <input type="url" id="scan-url-input" class="form-control" placeholder="https://example.com/page">
                </div>
                <button id="scan-btn" class="btn btn-primary">
                    <i class="fas fa-search mr-1"></i> {{ trans('vela::tools.pagespeed.scan') }}
                </button>
                <span id="scan-spinner" class="ml-2 d-none">
                    <i class="fas fa-spinner fa-spin"></i> {{ trans('vela::tools.pagespeed.scanning') }}
                </span>
                <div id="scan-result" class="mt-3"></div>
            </div>
        </div>

        {{-- Results Table --}}
        <div class="card">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.pagespeed.recent_results') }}</h5></div>
            <div class="card-body p-0">
                @if($results->isEmpty())
                    <div class="p-4 text-muted">{{ trans('vela::tools.pagespeed.no_results_yet') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="results-table">
                            <thead class="thead-light">
                                <tr>
                                    <th>{{ trans('vela::tools.pagespeed.url') }}</th>
                                    <th>{{ trans('vela::tools.pagespeed.performance') }}</th>
                                    <th>{{ trans('vela::tools.pagespeed.accessibility') }}</th>
                                    <th>{{ trans('vela::tools.pagespeed.seo') }}</th>
                                    <th>{{ trans('vela::tools.pagespeed.best_practices') }}</th>
                                    <th>{{ trans('vela::tools.common.date') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($results as $result)
                                    <tr class="result-row" data-id="{{ $result->id }}" style="cursor:pointer;" title="{{ trans('vela::tools.pagespeed.click_for_details') }}">
                                        <td>
                                            <span class="text-truncate d-inline-block" style="max-width:250px;" title="{{ $result->url }}">
                                                {{ $result->url }}
                                            </span>
                                        </td>
                                        <td>{!! scoreCell($result->performance_score) !!}</td>
                                        <td>{!! scoreCell($result->accessibility_score) !!}</td>
                                        <td>{!! scoreCell($result->seo_score) !!}</td>
                                        <td>{!! scoreCell($result->best_practices_score) !!}</td>
                                        <td>{{ $result->created_at->format('M j, Y H:i') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

    </div>
</div>

{{-- Detail Modal --}}
<div class="modal fade" id="result-modal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">{{ trans('vela::tools.pagespeed.details') }}</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <div class="modal-body" id="modal-body">
                <div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('vela::tools.common.close') }}</button>
            </div>
        </div>
    </div>
</div>
@endsection

@php
if (!function_exists('scoreCell')) {
    function scoreCell(?int $score): string
    {
        if ($score === null) return '<span class="text-muted">—</span>';
        if ($score >= 90) $class = 'badge-success';
        elseif ($score >= 50) $class = 'badge-warning';
        else $class = 'badge-danger';
        return "<span class=\"badge {$class}\">{$score}</span>";
    }
}
@endphp

@section('scripts')
<script>
(function () {
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var msgSelectOrEnterUrl = '{{ trans('vela::tools.pagespeed.select_or_enter_url') }}';
    var msgRecentScanExists = '{{ trans('vela::tools.pagespeed.recent_scan_exists') }}';
    var msgResultsPending = '{{ trans('vela::tools.pagespeed.results_pending') }}';
    var msgUnexpectedResponse = '{{ trans('vela::tools.pagespeed.unexpected_response') }}';
    var msgFailedToLoadDetails = '{{ trans('vela::tools.pagespeed.failed_to_load_details') }}';
    var msgPerformance = '{{ trans('vela::tools.pagespeed.performance') }}';
    var msgAccessibility = '{{ trans('vela::tools.pagespeed.accessibility') }}';
    var msgSeo = '{{ trans('vela::tools.pagespeed.seo') }}';
    var msgBestPractices = '{{ trans('vela::tools.pagespeed.best_practices') }}';
    var msgScanned = '{{ trans('vela::tools.pagespeed.scanned') }}';

    // Sync select -> input
    var select = document.getElementById('scan-url-select');
    var input = document.getElementById('scan-url-input');
    select.addEventListener('change', function () {
        if (this.value) input.value = this.value;
    });

    // Scan button
    document.getElementById('scan-btn').addEventListener('click', function () {
        var url = input.value.trim() || select.value;
        if (!url) {
            alert(msgSelectOrEnterUrl);
            return;
        }

        var btn = this;
        var spinner = document.getElementById('scan-spinner');
        var resultDiv = document.getElementById('scan-result');

        btn.disabled = true;
        spinner.classList.remove('d-none');
        resultDiv.innerHTML = '';

        fetch('{{ route('vela.admin.tools.pagespeed.scan') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({ url: url })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            btn.disabled = false;
            spinner.classList.add('d-none');

            if (data.result) {
                resultDiv.innerHTML = '<div class="alert alert-info">' + msgRecentScanExists + '</div>';
            } else if (data.message) {
                resultDiv.innerHTML = '<div class="alert alert-success"><i class="fas fa-check mr-1"></i>' + data.message + ' ' + msgResultsPending + '</div>';
            } else {
                resultDiv.innerHTML = '<div class="alert alert-danger">' + msgUnexpectedResponse + '</div>';
            }
        })
        .catch(function (err) {
            btn.disabled = false;
            spinner.classList.add('d-none');
            resultDiv.innerHTML = '<div class="alert alert-danger">' + '{{ trans('vela::tools.common.request_failed') }} ' + err.message + '</div>';
        });
    });

    // Row click -> modal
    document.querySelectorAll('.result-row').forEach(function (row) {
        row.addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            var modalBody = document.getElementById('modal-body');
            modalBody.innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
            $('#result-modal').modal('show');

            fetch('{{ url('admin/tools/pagespeed/results') }}/' + id, {
                headers: { 'X-CSRF-TOKEN': csrfToken }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var scores = [
                    { label: msgPerformance, value: data.performance_score },
                    { label: msgAccessibility, value: data.accessibility_score },
                    { label: msgSeo, value: data.seo_score },
                    { label: msgBestPractices, value: data.best_practices_score },
                ];

                var html = '<p><strong>URL:</strong> <a href="' + data.url + '" target="_blank">' + data.url + '</a></p>';
                html += '<p><strong>' + msgScanned + '</strong> ' + data.created_at + '</p>';
                html += '<div class="row text-center">';
                scores.forEach(function (s) {
                    var colorClass = s.value === null ? 'secondary' : (s.value >= 90 ? 'success' : (s.value >= 50 ? 'warning' : 'danger'));
                    var display = s.value !== null ? s.value : '—';
                    html += '<div class="col-md-3 mb-3">'
                          + '<div class="p-3 border rounded">'
                          + '<div style="font-size:2rem;font-weight:bold;" class="text-' + colorClass + '">' + display + '</div>'
                          + '<div class="text-muted">' + s.label + '</div>'
                          + '</div></div>';
                });
                html += '</div>';

                modalBody.innerHTML = html;
            })
            .catch(function () {
                modalBody.innerHTML = '<div class="alert alert-danger">' + msgFailedToLoadDetails + '</div>';
            });
        });
    });
})();
</script>
@endsection
