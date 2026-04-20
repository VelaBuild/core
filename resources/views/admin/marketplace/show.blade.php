@extends('vela::layouts.admin')

@section('content')
<div class="row">
    {{-- Main content --}}
    <div class="col-md-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <a href="{{ route('vela.admin.marketplace.index') }}" class="btn btn-sm btn-outline-secondary mr-2">
                        <i class="fas fa-arrow-left"></i>
                    </a>
                    <strong>{{ $plugin['name'] ?? '' }}</strong>
                    @if(!empty($plugin['current_version']))
                        <span class="text-muted ml-2">v{{ $plugin['current_version'] }}</span>
                    @endif
                </div>
                @if($installed)
                    <span class="badge badge-success"><i class="fas fa-check mr-1"></i> Installed</span>
                @endif
            </div>
            <div class="card-body">

                @if(session('error'))
                    <div class="alert alert-danger">{{ session('error') }}</div>
                @endif

                {{-- Description --}}
                <div class="mb-4">
                    <p class="lead text-muted">{{ $plugin['short_description'] ?? '' }}</p>
                    <div class="plugin-description">
                        {!! nl2br(e($plugin['description'] ?? '')) !!}
                    </div>
                </div>

                {{-- Metadata --}}
                <div class="card mb-4">
                    <div class="card-header"><h6 class="mb-0">Details</h6></div>
                    <div class="card-body p-0">
                        <table class="table table-sm mb-0">
                            <tbody>
                                @if(!empty($plugin['type']))
                                    <tr>
                                        <th style="width: 160px;">Type</th>
                                        <td><span class="badge badge-info">{{ ucfirst($plugin['type']) }}</span></td>
                                    </tr>
                                @endif
                                @if(!empty($plugin['developer']['display_name']))
                                    <tr>
                                        <th>Author</th>
                                        <td>{{ $plugin['developer']['display_name'] }}</td>
                                    </tr>
                                @endif
                                @if(!empty($plugin['min_core_version']))
                                    <tr>
                                        <th>Requires Vela</th>
                                        <td>{{ $plugin['min_core_version'] }}+</td>
                                    </tr>
                                @endif
                                @if(!empty($plugin['demo_url']))
                                    <tr>
                                        <th>Demo</th>
                                        <td><a href="{{ $plugin['demo_url'] }}" target="_blank" rel="noopener noreferrer">{{ $plugin['demo_url'] }} <i class="fas fa-external-link-alt fa-xs"></i></a></td>
                                    </tr>
                                @endif
                                @if(!empty($plugin['docs_url']))
                                    <tr>
                                        <th>Documentation</th>
                                        <td><a href="{{ $plugin['docs_url'] }}" target="_blank" rel="noopener noreferrer">{{ $plugin['docs_url'] }} <i class="fas fa-external-link-alt fa-xs"></i></a></td>
                                    </tr>
                                @endif
                                @if(!empty($plugin['support_url']))
                                    <tr>
                                        <th>Support</th>
                                        <td><a href="{{ $plugin['support_url'] }}" target="_blank" rel="noopener noreferrer">{{ $plugin['support_url'] }} <i class="fas fa-external-link-alt fa-xs"></i></a></td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Installation Instructions --}}
                @if(!empty($plugin['installation_instructions']))
                    <div class="card">
                        <div class="card-header"><h6 class="mb-0">Installation Instructions</h6></div>
                        <div class="card-body">
                            {!! nl2br(e($plugin['installation_instructions'])) !!}
                        </div>
                    </div>
                @endif

            </div>
        </div>
    </div>

    {{-- Sidebar --}}
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0">Get This Plugin</h6></div>
            <div class="card-body text-center">

                {{-- Price --}}
                <div class="mb-3">
                    @if(($plugin['price_type'] ?? '') === 'free' || ($plugin['price'] ?? 0) == 0)
                        <h3 class="text-success mb-0">Free</h3>
                    @else
                        <h3 class="mb-0">${{ number_format($plugin['price'] ?? 0, 2) }}</h3>
                        @if(($plugin['price_type'] ?? '') === 'yearly')
                            <small class="text-muted">/ year</small>
                        @else
                            <small class="text-muted">one-time</small>
                        @endif
                    @endif
                </div>

                {{-- License type badge --}}
                @if(!empty($plugin['price_type']))
                    <div class="mb-3">
                        @if($plugin['price_type'] === 'free')
                            <span class="badge badge-secondary">Free License</span>
                        @elseif($plugin['price_type'] === 'yearly')
                            <span class="badge badge-primary">Annual License</span>
                        @else
                            <span class="badge badge-success">Lifetime License</span>
                        @endif
                    </div>
                @endif

                {{-- Install / Installed button --}}
                @if($installed)
                    <div class="alert alert-success mb-0">
                        <i class="fas fa-check-circle mr-1"></i> Installed
                    </div>
                @elseif(($plugin['price_type'] ?? '') === 'free' || ($plugin['price'] ?? 0) == 0)
                    @php
                        $checkoutUrl = ($plugin['checkout_url'] ?? '#')
                            . '?domain=' . urlencode(config('app.url'))
                            . '&return_url=' . urlencode(route('vela.admin.marketplace.purchase.callback'));
                    @endphp
                    <a href="{{ $checkoutUrl }}" class="btn btn-success btn-block">
                        <i class="fas fa-download mr-1"></i> Install Free
                    </a>
                @else
                    @php
                        $checkoutUrl = ($plugin['checkout_url'] ?? '#')
                            . '?domain=' . urlencode(config('app.url'))
                            . '&return_url=' . urlencode(route('vela.admin.marketplace.purchase.callback'));
                    @endphp
                    <a href="{{ $checkoutUrl }}" class="btn btn-primary btn-block">
                        <i class="fas fa-shopping-cart mr-1"></i> Purchase &amp; Install
                    </a>
                @endif

            </div>
        </div>

        @if(!empty($plugin['composer_name']))
            <div class="card">
                <div class="card-header"><h6 class="mb-0">Package Info</h6></div>
                <div class="card-body">
                    <small class="text-muted d-block mb-1">Composer name:</small>
                    <code>{{ $plugin['composer_name'] }}</code>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
