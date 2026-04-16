@extends('vela::layouts.admin')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        @include('vela::admin.tools._nav', ['toolName' => trans('vela::tools.w3c.title'), 'toolIcon' => 'fas fa-check-circle'])
        <span class="badge badge-success">{{ trans('vela::tools.common.ready') }}</span>
    </div>
    <div class="card-body">

        {{-- URL Form --}}
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.w3c.validate_a_page') }}</h5></div>
            <div class="card-body">
                <div class="form-group">
                    <label for="validate-url">{{ trans('vela::tools.w3c.page_url') }}</label>
                    <input type="url" id="validate-url" class="form-control"
                        placeholder="https://example.com/page"
                        value="{{ url('/') }}">
                    <small class="form-text text-muted">{{ trans('vela::tools.w3c.url_must_be_accessible') }}</small>
                </div>
                <button id="validate-btn" class="btn btn-primary">
                    <i class="fas fa-check mr-1"></i> {{ trans('vela::tools.w3c.validate') }}
                </button>
                <span id="validate-spinner" class="ml-2 d-none">
                    <i class="fas fa-spinner fa-spin"></i> {{ trans('vela::tools.w3c.validating') }}
                </span>
            </div>
        </div>

        {{-- Results --}}
        <div id="validate-results" class="d-none">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">{{ trans('vela::tools.w3c.validation_results') }}</h5>
                    <div id="results-summary"></div>
                </div>
                <div class="card-body p-0">
                    <div id="results-list"></div>
                </div>
            </div>
        </div>

        {{-- Error --}}
        <div id="validate-error" class="d-none">
            <div class="alert alert-danger" id="error-message"></div>
        </div>

    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var msgEnterUrl = '{{ trans('vela::tools.w3c.enter_url') }}';
    var msgValidationFailed = '{{ trans('vela::tools.w3c.validation_failed') }}';
    var msgErrorType = '{{ trans('vela::tools.w3c.error_type') }}';
    var msgWarningType = '{{ trans('vela::tools.w3c.warning_type') }}';
    var msgInfoType = '{{ trans('vela::tools.w3c.info_type') }}';
    var msgNoIssuesFound = '{{ trans('vela::tools.w3c.no_issues_found') }}';
    var msgValidHtml = '{{ trans('vela::tools.w3c.valid_html') }}';
    var msgValidHtmlMessage = '{{ trans('vela::tools.w3c.valid_html_message') }}';
    var msgType = '{{ trans('vela::tools.w3c.type') }}';
    var msgMessage = '{{ trans('vela::tools.w3c.message') }}';
    var msgLocation = '{{ trans('vela::tools.w3c.location') }}';
    var msgLine = '{{ trans('vela::tools.w3c.line') }}';
    var msgCol = '{{ trans('vela::tools.w3c.col') }}';
    var msgRequestFailed = '{{ trans('vela::tools.common.request_failed') }}';

    document.getElementById('validate-btn').addEventListener('click', function () {
        var url = document.getElementById('validate-url').value.trim();
        if (!url) {
            alert(msgEnterUrl);
            return;
        }

        var btn = this;
        var spinner = document.getElementById('validate-spinner');
        var resultsDiv = document.getElementById('validate-results');
        var errorDiv = document.getElementById('validate-error');

        btn.disabled = true;
        spinner.classList.remove('d-none');
        resultsDiv.classList.add('d-none');
        errorDiv.classList.add('d-none');

        fetch('{{ route('vela.admin.tools.w3c-validator.validate') }}', {
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

            if (!data.success) {
                errorDiv.classList.remove('d-none');
                document.getElementById('error-message').textContent = data.message || msgValidationFailed;
                return;
            }

            resultsDiv.classList.remove('d-none');

            var messages = data.messages || [];
            var errors = messages.filter(function (m) { return m.type === 'error'; });
            var warnings = messages.filter(function (m) { return m.type === 'info' && m.subType === 'warning'; });
            var infos = messages.filter(function (m) { return m.type === 'info' && m.subType !== 'warning'; });

            var summaryEl = document.getElementById('results-summary');
            var summaryParts = [];
            if (errors.length > 0) summaryParts.push('<span class="badge badge-danger ml-1">' + errors.length + ' ' + msgErrorType + (errors.length !== 1 ? 's' : '') + '</span>');
            if (warnings.length > 0) summaryParts.push('<span class="badge badge-warning ml-1">' + warnings.length + ' ' + msgWarningType + (warnings.length !== 1 ? 's' : '') + '</span>');
            if (infos.length > 0) summaryParts.push('<span class="badge badge-info ml-1">' + infos.length + ' ' + msgInfoType + '</span>');
            if (messages.length === 0) summaryParts.push('<span class="badge badge-success ml-1">' + msgNoIssuesFound + '</span>');
            summaryEl.innerHTML = summaryParts.join('');

            var listEl = document.getElementById('results-list');

            if (messages.length === 0) {
                listEl.innerHTML = '<div class="p-4 text-center text-success"><i class="fas fa-check-circle fa-2x mb-2"></i><br><strong>' + msgValidHtml + '</strong> ' + msgValidHtmlMessage + '</div>';
                return;
            }

            var html = '<table class="table table-sm mb-0">'
                     + '<thead class="thead-light"><tr><th width="100">' + msgType + '</th><th>' + msgMessage + '</th><th width="120">' + msgLocation + '</th></tr></thead>'
                     + '<tbody>';

            messages.forEach(function (msg) {
                var badgeClass, typeLabel;
                if (msg.type === 'error') {
                    badgeClass = 'badge-danger';
                    typeLabel = msgErrorType;
                } else if (msg.subType === 'warning') {
                    badgeClass = 'badge-warning';
                    typeLabel = msgWarningType;
                } else {
                    badgeClass = 'badge-info';
                    typeLabel = msgInfoType;
                }

                var location = '';
                if (msg.lastLine) {
                    location = msgLine + ' ' + msg.lastLine;
                    if (msg.lastColumn) location += ', ' + msgCol + ' ' + msg.lastColumn;
                }

                html += '<tr>'
                      + '<td><span class="badge ' + badgeClass + '">' + typeLabel + '</span></td>'
                      + '<td>' + escapeHtml(msg.message || '') + (msg.extract ? '<br><code class="text-muted" style="font-size:0.8em;">' + escapeHtml(msg.extract) + '</code>' : '') + '</td>'
                      + '<td><small class="text-muted">' + location + '</small></td>'
                      + '</tr>';
            });

            html += '</tbody></table>';
            listEl.innerHTML = html;
        })
        .catch(function (err) {
            btn.disabled = false;
            spinner.classList.add('d-none');
            errorDiv.classList.remove('d-none');
            document.getElementById('error-message').textContent = msgRequestFailed + ' ' + err.message;
        });
    });

    function escapeHtml(str) {
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
})();
</script>
@endsection
