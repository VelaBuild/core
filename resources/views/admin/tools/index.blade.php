@php use Illuminate\Support\Facades\Gate; @endphp
@extends('vela::layouts.admin')

@section('content')
<div class="card">
    <div class="card-header">
        <h4><i class="fas fa-toolbox"></i> {{ trans('vela::tools.title') }}</h4>
    </div>
    <div class="card-body">
        @foreach($tools as $category => $categoryTools)
            <h5 class="text-muted text-uppercase mb-3 mt-4">{{ trans('vela::tools.category_' . $category) }}</h5>
            <div class="row">
                @foreach($categoryTools as $name => $tool)
                    @php
                        $status = $statuses[$name] ?? 'not_configured';
                        $hasAccess = Gate::allows($tool['gate'] ?? 'tools_access');
                        $statusColor = match($status) {
                            'connected' => 'success',
                            'error' => 'danger',
                            default => 'warning',
                        };
                        $statusLabel = match($status) {
                            'connected' => trans('vela::global.connected'),
                            'error' => trans('vela::global.error'),
                            default => trans('vela::global.not_configured'),
                        };
                    @endphp
                    <div class="col-md-4 mb-3">
                        <div class="card h-100 {{ !$hasAccess ? 'opacity-50' : '' }}">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <i class="{{ $tool['icon'] }} fa-2x text-primary mb-2"></i>
                                        <h5 class="card-title">{{ trans($tool['label']) }}</h5>
                                    </div>
                                    <span class="badge badge-{{ $statusColor }}">{{ $statusLabel }}</span>
                                </div>
                                <p class="card-text text-muted">{{ trans($tool['description']) }}</p>
                                @if($hasAccess)
                                    <a href="{{ $tool['route'] !== '#' ? route($tool['route']) : '#' }}" class="btn btn-sm btn-primary">
                                        {{ $status === 'not_configured' ? trans('vela::global.set_up') : trans('vela::global.open') }}
                                    </a>
                                @else
                                    <span class="text-muted"><i class="fas fa-lock"></i> {{ trans('vela::global.admin_access_required') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
@endsection
