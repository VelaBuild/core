@extends('vela::layouts.admin')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        @include('vela::admin.tools._nav', ['toolName' => trans('vela::tools.repostra.title'), 'toolIcon' => 'fas fa-rss'])
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

        {{-- Webhook URL --}}
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.repostra.webhook_url') }}</h5></div>
            <div class="card-body">
                <p class="text-muted">{{ trans('vela::tools.repostra.webhook_url_help') }}</p>
                <div class="input-group">
                    <input type="text" id="webhook-url" class="form-control" value="{{ $webhookUrl }}" readonly>
                    <div class="input-group-append">
                        <button class="btn btn-outline-secondary" id="copy-webhook-btn" type="button">
                            <i class="fas fa-copy mr-1"></i> {{ trans('vela::tools.common.copy') }}
                        </button>
                    </div>
                </div>
                <div id="copy-feedback" class="text-success mt-1 d-none">
                    <i class="fas fa-check mr-1"></i> {{ trans('vela::tools.repostra.copied_to_clipboard') }}
                </div>
            </div>
        </div>

        @if($canConfigure)
        {{-- Config Form --}}
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.common.configuration') }}</h5></div>
            <div class="card-body">
                <form method="POST" action="{{ route('vela.admin.tools.repostra.config') }}">
                    @csrf

                    <div class="form-group">
                        <label>{{ trans('vela::tools.repostra.webhook_secret') }}</label>
                        <input type="password"
                               name="repostra_webhook_secret"
                               class="form-control"
                               placeholder="{{ $isConfigured ? trans('vela::tools.repostra.webhook_secret_placeholder_set') : trans('vela::tools.repostra.webhook_secret_placeholder_empty') }}"
                               value="">
                        <small class="text-muted">{{ trans('vela::tools.repostra.webhook_secret_help') }}</small>
                    </div>

                    <div class="form-group">
                        <label>{{ trans('vela::tools.repostra.default_import_status') }}</label>
                        <select name="repostra_default_status" class="form-control">
                            <option value="draft" {{ $defaultStatus === 'draft' ? 'selected' : '' }}>{{ trans('vela::tools.repostra.draft_option') }}</option>
                            <option value="published" {{ $defaultStatus === 'published' ? 'selected' : '' }}>{{ trans('vela::tools.repostra.published_option') }}</option>
                        </select>
                        <small class="text-muted">{{ trans('vela::tools.repostra.import_status_help') }}</small>
                    </div>

                    <div class="form-group">
                        <label>{{ trans('vela::tools.repostra.default_author_id') }}</label>
                        <input type="number"
                               name="repostra_default_author_id"
                               class="form-control"
                               value="{{ $defaultAuthorId }}"
                               placeholder="e.g. 1">
                        <small class="text-muted">{{ trans('vela::tools.repostra.default_author_id_help') }}</small>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save mr-1"></i> {{ trans('vela::tools.common.save_settings') }}
                    </button>
                </form>
            </div>
        </div>
        @endif

        @if(!$isConfigured)
            <div class="alert alert-info">
                <i class="fas fa-info-circle mr-1"></i>
                {{ trans('vela::tools.repostra.not_configured_info') }}
            </div>
        @endif

        {{-- Recent Imports --}}
        <div class="card">
            <div class="card-header"><h5 class="mb-0">{{ trans('vela::tools.repostra.recent_imports') }}</h5></div>
            <div class="card-body p-0">
                @if($recentImports->isEmpty())
                    <div class="p-4 text-muted">{{ trans('vela::tools.repostra.no_imports_yet') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>{{ trans('vela::global.title') }}</th>
                                    <th>{{ trans('vela::tools.common.status') }}</th>
                                    <th>{{ trans('vela::tools.repostra.imported_at') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentImports as $import)
                                    <tr>
                                        <td>{{ $import->title }}</td>
                                        <td>
                                            @if($import->status === 'published')
                                                <span class="badge badge-success">{{ trans('vela::tools.common.published') }}</span>
                                            @elseif($import->status === 'draft')
                                                <span class="badge badge-secondary">{{ trans('vela::tools.common.draft') }}</span>
                                            @else
                                                <span class="badge badge-light">{{ $import->status }}</span>
                                            @endif
                                        </td>
                                        <td>{{ $import->created_at }}</td>
                                        <td>
                                            @if(Route::has('vela.admin.content.edit'))
                                                <a href="{{ route('vela.admin.content.edit', $import->id) }}" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-edit"></i> {{ trans('vela::global.edit') }}
                                                </a>
                                            @endif
                                        </td>
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
@endsection

@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    var copyBtn = document.getElementById('copy-webhook-btn');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var input = document.getElementById('webhook-url');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(input.value).then(function () {
                    showCopied();
                });
            } else {
                input.select();
                document.execCommand('copy');
                showCopied();
            }
        });
    }

    function showCopied() {
        var fb = document.getElementById('copy-feedback');
        fb.classList.remove('d-none');
        setTimeout(function () { fb.classList.add('d-none'); }, 2000);
    }
});
</script>
@endsection
