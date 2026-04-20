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

        @if(session('message'))
            <div class="alert alert-success">{{ session('message') }}</div>
        @endif

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
                                    <div class="btn-group btn-group-sm">
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
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </div>
</div>
@endsection
