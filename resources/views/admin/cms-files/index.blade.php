@extends('vela::layouts.admin')

@php
    $labels = [
        'current'            => ['secondary', 'Current'],
        'update'             => ['info', 'Update (safe)'],
        'add'                => ['info', 'New (safe)'],
        'review'             => ['warning', 'Needs review'],
        'customised'         => ['light', 'Customised'],
        'deleted'            => ['light', 'Deleted by site'],
        'remove'             => ['warning', 'Removed upstream'],
        'removed-customised' => ['warning', 'Removed upstream, customised'],
        'kept'               => ['light', 'Kept'],
    ];
    $pending = $files->whereIn('status', \VelaBuild\Core\Services\CmsFileUpgrader::PENDING);
    $safe = $files->where('safe', true);
    $actionLabels = ['overwrite' => 'Use Vela\'s version', 'add' => 'Add', 'merge' => 'Merge', 'keep' => 'Keep mine', 'skip' => 'Skip for now', 'delete' => 'Delete'];
@endphp

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="mb-0"><i class="fas fa-code-branch mr-2"></i> CMS files <small class="text-muted">· Vela {{ $version }}</small></h4>
        @if($safe->isNotEmpty())
            <form method="POST" action="{{ route('vela.admin.cms-files.apply-safe') }}">
                @csrf
                <button class="btn btn-sm btn-primary"><i class="fas fa-check mr-1"></i> Apply {{ $safe->count() }} safe update(s)</button>
            </form>
        @endif
    </div>
    <div class="card-body">
        <p class="text-muted">
            The starter files your site was installed with — the front controller, bootstrap, config, routes and error pages.
            Files you never changed update safely; files you and Vela both changed need a decision. Every change keeps a backup in
            <code>storage/app/vela-skeleton/backups</code>. Same as <code>php artisan vela:upgrade-files</code>.
        </p>

        @if(session('message'))
            <div class="alert alert-success">{{ session('message') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        @if($pending->isEmpty())
            <div class="alert alert-success mb-3"><i class="fas fa-check mr-1"></i> Nothing to do — every starter file is current or deliberately yours.</div>
        @endif

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr><th>File</th><th>Status</th><th class="text-right">Actions</th></tr>
                </thead>
                <tbody>
                @foreach($files as $file)
                    @continue($file['status'] === 'current')
                    @php [$tone, $label] = $labels[$file['status']]; @endphp
                    <tr>
                        <td>
                            <code>{{ $file['path'] }}</code>
                            @if($file['critical']) <span class="badge badge-danger ml-1" title="The site will not boot if this file is wrong">critical</span> @endif
                            @if(!empty($file['reason'])) <div><small class="text-muted">{{ $file['reason'] }}</small></div> @endif
                        </td>
                        <td><span class="badge badge-{{ $tone }}">{{ $label }}</span> @if($file['merge']) <small class="text-muted">mergeable</small> @endif</td>
                        <td class="text-right text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener"
                               href="{{ route('vela.admin.cms-files.diff', ['path' => $file['path']]) }}">Diff</a>
                            @foreach($file['actions'] as $action)
                                <form method="POST" action="{{ route('vela.admin.cms-files.apply') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="path" value="{{ $file['path'] }}">
                                    <input type="hidden" name="action" value="{{ $action }}">
                                    <button class="btn btn-sm {{ in_array($action, ['overwrite', 'add', 'merge']) ? 'btn-outline-primary' : ($action === 'delete' ? 'btn-outline-danger' : 'btn-outline-secondary') }}">{{ $actionLabels[$action] }}</button>
                                </form>
                            @endforeach
                            @if(session('conflict_path') === $file['path'])
                                <form method="POST" action="{{ route('vela.admin.cms-files.apply') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="path" value="{{ $file['path'] }}">
                                    <input type="hidden" name="action" value="merge">
                                    <input type="hidden" name="allow_conflicts" value="1">
                                    <button class="btn btn-sm btn-outline-warning" title="Writes the file with conflict markers for you to resolve by hand">Merge with conflict markers</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-muted mt-3 mb-0"><small>{{ $files->where('status', 'current')->count() }} other file(s) already match this release.</small></p>
    </div>
</div>
@endsection
