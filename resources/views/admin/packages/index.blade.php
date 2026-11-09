@extends('vela::layouts.admin')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="mb-0"><i class="fas fa-puzzle-piece mr-2"></i> Installed Packages</h4>
        <a href="{{ route('vela.admin.marketplace.index') }}" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-store mr-1"></i> Browse Marketplace
        </a>
    </div>
    <div class="card-body">

        @if($safeMode)
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                Safe mode is active. All marketplace plugins are disabled.
            </div>
        @endif

        @foreach(['message', 'success'] as $flash)
            @if(session($flash))
                <div class="alert alert-success">{{ session($flash) }}</div>
            @endif
        @endforeach

        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        @if($packages->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="fas fa-puzzle-piece fa-3x mb-3 d-block"></i>
                <p class="mb-2">No marketplace packages installed.</p>
                <a href="{{ route('vela.admin.marketplace.index') }}" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-store mr-1"></i> Browse the marketplace to get started
                </a>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Package</th>
                            <th>Version</th>
                            <th>License Type</th>
                            <th>Status</th>
                            <th>Expires</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($packages as $package)
                            <tr>
                                <td>
                                    <div><strong>{{ $package->composer_name }}</strong></div>
                                    <small class="text-muted">{{ $package->vendor_name }}/{{ $package->package_name }}</small>
                                </td>
                                <td>
                                    {{ $package->version }}
                                    @php
                                        $updateKey = 'marketplace_update_' . str_replace('/', '_', $package->composer_name);
                                        $updateAvailable = config('vela.' . $updateKey) ?? \VelaBuild\Core\Models\VelaConfig::where('key', $updateKey)->value('value');
                                    @endphp
                                    @if($updateAvailable)
                                        <span class="badge badge-warning ml-1">Update available</span>
                                    @endif
                                </td>
                                <td>
                                    @if($package->license)
                                        @if($package->license->type === 'free')
                                            <span class="badge badge-secondary">Free</span>
                                        @elseif($package->license->type === 'yearly')
                                            <span class="badge badge-primary">Annual</span>
                                        @else
                                            <span class="badge badge-success">Lifetime</span>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if($package->status === 'active')
                                        <span class="badge badge-success">Active</span>
                                    @elseif($package->status === 'disabled')
                                        <span class="badge badge-warning">Disabled</span>
                                    @elseif($package->status === 'expired')
                                        <span class="badge badge-danger">Expired</span>
                                    @elseif($package->status === 'suspended')
                                        <span class="badge badge-danger">Suspended</span>
                                    @elseif($package->isBusy())
                                        <span class="badge badge-info">
                                            <i class="fas fa-spinner fa-spin mr-1"></i>{{ ucfirst($package->status) }}…
                                        </span>
                                        <div class="small text-muted mt-1">Started {{ $package->operation_started_at?->diffForHumans() }}</div>
                                    @elseif($package->status === 'failed')
                                        <span class="badge badge-danger">Install failed</span>
                                    @else
                                        <span class="badge badge-secondary">{{ $package->status }}</span>
                                    @endif
                                </td>
                                <td>
                                    @if($package->license && $package->license->expires_at)
                                        <span class="{{ $package->license->expires_at->isPast() ? 'text-danger' : 'text-muted' }}">
                                            {{ $package->license->expires_at->format('Y-m-d') }}
                                        </span>
                                    @else
                                        <span class="text-muted">Never</span>
                                    @endif
                                </td>
                                <td>
                                    @if($package->isBusy())
                                        <span class="text-muted small">Please wait…</span>
                                    @else
                                    <div class="btn-group btn-group-sm">
                                        @if($package->status === 'failed')
                                            <form method="POST" action="{{ route('vela.admin.packages.retry', $package->id) }}" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Try the install again">
                                                    <i class="fas fa-redo"></i> Retry
                                                </button>
                                            </form>
                                        @endif
                                        {{-- Enable / Disable toggle --}}
                                        @if($package->status === 'disabled')
                                            <form method="POST" action="{{ route('vela.admin.packages.enable', $package->id) }}" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-success" title="Enable">
                                                    <i class="fas fa-play"></i> Enable
                                                </button>
                                            </form>
                                        @elseif($package->status === 'active')
                                            <form method="POST" action="{{ route('vela.admin.packages.disable', $package->id) }}" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Disable">
                                                    <i class="fas fa-pause"></i> Disable
                                                </button>
                                            </form>
                                        @endif

                                        {{-- Update button (only when update available) --}}
                                        @if($updateAvailable)
                                            <form method="POST" action="{{ route('vela.admin.packages.update', $package->id) }}" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Update">
                                                    <i class="fas fa-sync-alt"></i> Update
                                                </button>
                                            </form>
                                        @endif

                                        {{-- Remove --}}
                                        <form method="POST" action="{{ route('vela.admin.packages.destroy', $package->id) }}" class="d-inline" onsubmit="return confirm('Are you sure you want to remove {{ addslashes($package->composer_name) }}? This will uninstall the package via Composer.')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                                <i class="fas fa-trash"></i> Remove
                                            </button>
                                        </form>
                                    </div>
                                    @endif
                                </td>
                            </tr>
                            @if($package->operation_error || ($package->operation_log && $package->status === 'failed'))
                                <tr class="table-light">
                                    <td colspan="6" class="small">
                                        @if($package->operation_error)
                                            <div class="text-danger mb-1"><i class="fas fa-exclamation-circle mr-1"></i>{{ $package->operation_error }}</div>
                                        @endif
                                        @if($package->operation_log)
                                            <details>
                                                <summary class="text-muted">Composer output</summary>
                                                <pre class="mb-0 mt-2 p-2 bg-white border rounded" style="max-height: 280px; overflow: auto; white-space: pre-wrap;">{{ $package->operation_log }}</pre>
                                            </details>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </div>
</div>

@if($packages->contains(fn ($p) => $p->isBusy()))
    @push('scripts')
    <script>
    // Follow the background job: reload when any package's status changes.
    (function () {
        var url = @json(route('vela.admin.packages.status'));
        var seen = @json($packages->mapWithKeys(fn ($p) => [$p->id => $p->status]));
        setInterval(function () {
            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.ok ? r.json() : []; })
                .then(function (rows) {
                    var changed = rows.length !== Object.keys(seen).length
                        || rows.some(function (p) { return seen[p.id] !== p.status; });
                    if (changed) window.location.reload();
                })
                .catch(function () {});
        }, 4000);
    })();
    </script>
    @endpush
@endif
@endsection
