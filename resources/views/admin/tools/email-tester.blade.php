@extends('vela::layouts.admin')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        @include('vela::admin.tools._nav', ['toolName' => trans('vela::tools.email.title'), 'toolIcon' => 'fas fa-envelope'])
        @if($mailConfig['driver'] && $mailConfig['driver'] !== 'log')
            <span class="badge badge-success">{{ trans('vela::tools.common.configured') }}</span>
        @else
            <span class="badge badge-warning">{{ trans('vela::tools.common.not_configured') }}</span>
        @endif
    </div>
    <div class="card-body">

        {{-- Current Mail Config --}}
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.email.current_mail_configuration') }}</h5></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm mb-0">
                            <tbody>
                                <tr>
                                    <th width="40%">{{ trans('vela::tools.email.driver') }}</th>
                                    <td>
                                        {{ $mailConfig['driver'] ?? '—' }}
                                        @if(!$mailConfig['driver'] || $mailConfig['driver'] === 'log')
                                            <span class="badge badge-warning ml-1">{{ trans('vela::tools.email.log_only_warning') }}</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th>{{ trans('vela::tools.email.host') }}</th>
                                    <td>{{ $mailConfig['host'] ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <th>{{ trans('vela::tools.email.port') }}</th>
                                    <td>{{ $mailConfig['port'] ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <th>{{ trans('vela::tools.email.encryption') }}</th>
                                    <td>{{ $mailConfig['encryption'] ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <th>{{ trans('vela::tools.email.from_address') }}</th>
                                    <td>{{ $mailConfig['from_address'] ?? '—' }}</td>
                                </tr>
                                <tr>
                                    <th>{{ trans('vela::tools.email.from_name') }}</th>
                                    <td>{{ $mailConfig['from_name'] ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        @if(!$mailConfig['driver'] || $mailConfig['driver'] === 'log')
                            <div class="alert alert-warning mb-0">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                <strong>{{ trans('vela::tools.email.driver_not_configured') }}</strong><br>
                                {!! trans('vela::tools.email.driver_not_configured_help') !!}
                                <br><small>{!! trans('vela::tools.email.driver_not_configured_example') !!}</small>
                            </div>
                        @else
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle mr-1"></i>
                                {{ trans('vela::tools.email.mail_configured_info') }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Test Form --}}
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.email.send_test_email') }}</h5></div>
            <div class="card-body">
                <div class="form-group">
                    <label for="to-email">{{ trans('vela::tools.email.to_address') }}</label>
                    <input type="email" id="to-email" class="form-control"
                        value="{{ auth('vela')->user()->email ?? '' }}"
                        placeholder="recipient@example.com">
                </div>
                <div class="form-group">
                    <label for="email-subject">{{ trans('vela::tools.email.subject') }}</label>
                    <input type="text" id="email-subject" class="form-control"
                        value="{{ trans('vela::tools.email.default_subject') }}"
                        placeholder="{{ trans('vela::tools.email.subject') }}">
                </div>
                <button id="send-btn" class="btn btn-primary">
                    <i class="fas fa-paper-plane mr-1"></i> {{ trans('vela::tools.email.send_test_email') }}
                </button>
                <span id="send-spinner" class="ml-2 d-none">
                    <i class="fas fa-spinner fa-spin"></i> {{ trans('vela::tools.email.sending') }}
                </span>
                <small class="text-muted ml-3">{{ trans('vela::tools.email.rate_limit') }}</small>
            </div>
        </div>

        {{-- Result display --}}
        <div id="send-result" class="d-none">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.common.result') }}</h5></div>
                <div class="card-body">
                    <div id="result-badge" class="mb-2"></div>
                    <p id="result-message" class="mb-2"></p>
                    <div id="result-diagnostics"></div>
                </div>
            </div>
        </div>

    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var msgEnterRecipient = '{{ trans('vela::tools.email.enter_recipient') }}';
    var msgRateLimited = '{{ trans('vela::tools.email.rate_limited') }}';
    var msgRateLimitedMessage = '{{ trans('vela::tools.email.rate_limited_message') }}';
    var msgFailed = '{{ trans('vela::tools.common.failed') }}';
    var msgDiagnostics = '{{ trans('vela::tools.email.diagnostics') }}';
    var msgDriver = '{{ trans('vela::tools.email.driver') }}';
    var msgElapsed = '{{ trans('vela::tools.email.elapsed') }}';
    var msgError = '{{ trans('vela::tools.email.error') }}';

    document.getElementById('send-btn').addEventListener('click', function () {
        var to = document.getElementById('to-email').value.trim();
        var subject = document.getElementById('email-subject').value.trim();

        if (!to) {
            alert(msgEnterRecipient);
            return;
        }

        var btn = this;
        var spinner = document.getElementById('send-spinner');
        var resultDiv = document.getElementById('send-result');

        btn.disabled = true;
        spinner.classList.remove('d-none');
        resultDiv.classList.add('d-none');

        fetch('{{ route('vela.admin.tools.email-tester.send') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify({ to: to, subject: subject })
        })
        .then(function (res) { return res.json().then(function (data) { return { status: res.status, data: data }; }); })
        .then(function (res) {
            btn.disabled = false;
            spinner.classList.add('d-none');
            resultDiv.classList.remove('d-none');

            var data = res.data;
            var badgeEl = document.getElementById('result-badge');
            var msgEl = document.getElementById('result-message');
            var diagEl = document.getElementById('result-diagnostics');

            if (res.status === 429) {
                badgeEl.innerHTML = '<span class="badge badge-danger badge-lg">' + msgRateLimited + '</span>';
                msgEl.textContent = data.message || msgRateLimitedMessage;
                diagEl.innerHTML = '';
                return;
            }

            if (data.success) {
                badgeEl.innerHTML = '<span class="badge badge-success" style="font-size:1rem;">{{ trans('vela::global.success') }}</span>';
            } else {
                badgeEl.innerHTML = '<span class="badge badge-danger" style="font-size:1rem;">' + msgFailed + '</span>';
            }

            msgEl.textContent = data.message || '';

            var diag = data.diagnostics || {};
            var diagHtml = '<table class="table table-sm table-bordered mb-0" style="max-width:400px;">';
            diagHtml += '<thead class="thead-light"><tr><th colspan="2">' + msgDiagnostics + '</th></tr></thead><tbody>';
            if (diag.driver !== undefined) diagHtml += '<tr><th>' + msgDriver + '</th><td>' + diag.driver + '</td></tr>';
            if (diag.elapsed_ms !== undefined) diagHtml += '<tr><th>' + msgElapsed + '</th><td>' + diag.elapsed_ms + ' ms</td></tr>';
            if (diag.error !== undefined) diagHtml += '<tr><th>' + msgError + '</th><td class="text-danger">' + diag.error + '</td></tr>';
            diagHtml += '</tbody></table>';
            diagEl.innerHTML = diagHtml;
        })
        .catch(function (err) {
            btn.disabled = false;
            spinner.classList.add('d-none');
            resultDiv.classList.remove('d-none');
            document.getElementById('result-badge').innerHTML = '<span class="badge badge-danger" style="font-size:1rem;">{{ trans('vela::global.error') }}</span>';
            document.getElementById('result-message').textContent = '{{ trans('vela::tools.common.request_failed') }} ' + err.message;
            document.getElementById('result-diagnostics').innerHTML = '';
        });
    });
})();
</script>
@endsection
