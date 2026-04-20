@extends('vela::layouts.admin')

@section('content')
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4 class="mb-0"><i class="fas fa-store mr-2"></i> Marketplace</h4>
        <div class="d-flex align-items-center" style="gap: 10px;">
            <input type="text" id="marketplace-search" class="form-control form-control-sm" placeholder="Search plugins..." style="width: 220px;" value="{{ $filters['search'] ?? '' }}">
        </div>
    </div>
    <div class="card-body">

        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        {{-- Filter bar --}}
        <div class="d-flex align-items-center mb-4" style="gap: 12px; flex-wrap: wrap;">
            <div>
                <select id="filter-type" class="form-control form-control-sm">
                    <option value="">All Types</option>
                    <option value="block" {{ ($filters['type'] ?? '') === 'block' ? 'selected' : '' }}>Block</option>
                    <option value="template" {{ ($filters['type'] ?? '') === 'template' ? 'selected' : '' }}>Template</option>
                    <option value="widget" {{ ($filters['type'] ?? '') === 'widget' ? 'selected' : '' }}>Widget</option>
                    <option value="tool" {{ ($filters['type'] ?? '') === 'tool' ? 'selected' : '' }}>Tool</option>
                    <option value="theme" {{ ($filters['type'] ?? '') === 'theme' ? 'selected' : '' }}>Theme</option>
                    <option value="integration" {{ ($filters['type'] ?? '') === 'integration' ? 'selected' : '' }}>Integration</option>
                </select>
            </div>
            <div>
                <select id="filter-price" class="form-control form-control-sm">
                    <option value="">All Prices</option>
                    <option value="free" {{ ($filters['price'] ?? '') === 'free' ? 'selected' : '' }}>Free</option>
                    <option value="paid" {{ ($filters['price'] ?? '') === 'paid' ? 'selected' : '' }}>Paid</option>
                </select>
            </div>
        </div>

        {{-- Plugin grid --}}
        <div id="plugins-grid">
            @if(empty($plugins) || (is_array($plugins) && empty($plugins['data'] ?? $plugins)))
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-store fa-3x mb-3 d-block"></i>
                    <p class="mb-2">The marketplace is new &mdash; be an early contributor!</p>
                    <a href="https://marketplace.vela.build/developer" target="_blank" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-code mr-1"></i> Submit a Plugin
                    </a>
                </div>
            @else
                @php
                    $pluginList = $plugins['data'] ?? $plugins;
                @endphp
                <div class="row" id="plugins-row">
                    @foreach($pluginList as $plugin)
                        <div class="col-md-4 mb-4">
                            <div class="card h-100">
                                <div class="card-body d-flex flex-column">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h5 class="card-title mb-0">{{ $plugin['name'] ?? '' }}</h5>
                                        <span class="badge badge-info ml-2">{{ ucfirst($plugin['type'] ?? '') }}</span>
                                    </div>
                                    <p class="card-text text-muted flex-grow-1">{{ $plugin['short_description'] ?? '' }}</p>
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <span class="font-weight-bold">
                                            @if(($plugin['price_type'] ?? '') === 'free' || ($plugin['price'] ?? 0) == 0)
                                                <span class="text-success">Free</span>
                                            @else
                                                ${{ number_format($plugin['price'] ?? 0, 2) }}
                                            @endif
                                        </span>
                                        <a href="{{ route('vela.admin.marketplace.show', $plugin['slug'] ?? '') }}" class="btn btn-sm btn-primary">
                                            View
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Pagination --}}
                @if(!empty($plugins['next_page_url']) || !empty($plugins['prev_page_url']))
                    <div class="d-flex justify-content-center mt-3">
                        <nav>
                            <ul class="pagination pagination-sm">
                                @if(!empty($plugins['prev_page_url']))
                                    <li class="page-item">
                                        <a class="page-link" href="{{ $plugins['prev_page_url'] }}">&laquo; Previous</a>
                                    </li>
                                @endif
                                @if(!empty($plugins['next_page_url']))
                                    <li class="page-item">
                                        <a class="page-link" href="{{ $plugins['next_page_url'] }}">Next &raquo;</a>
                                    </li>
                                @endif
                            </ul>
                        </nav>
                    </div>
                @endif
            @endif
        </div>

    </div>
</div>
@endsection

@section('scripts')
<script>
$(document).ready(function () {
    var searchTimer = null;
    var searchUrl = '{{ route('vela.admin.marketplace.search') }}';

    function doSearch() {
        var search = $('#marketplace-search').val();
        var type = $('#filter-type').val();
        var price = $('#filter-price').val();

        $.get(searchUrl, { search: search, type: type, price: price }, function (data) {
            var plugins = data.data || data;
            var html = '';

            if (!plugins || plugins.length === 0) {
                html = '<div class="text-center py-5 text-muted">'
                     + '<i class="fas fa-search fa-3x mb-3 d-block"></i>'
                     + '<p>No plugins found matching your search.</p>'
                     + '</div>';
                $('#plugins-grid').html(html);
                return;
            }

            html = '<div class="row" id="plugins-row">';
            $.each(plugins, function (i, plugin) {
                var price_label = (plugin.price_type === 'free' || plugin.price == 0)
                    ? '<span class="text-success">Free</span>'
                    : '$' + parseFloat(plugin.price).toFixed(2);

                html += '<div class="col-md-4 mb-4">'
                      + '<div class="card h-100">'
                      + '<div class="card-body d-flex flex-column">'
                      + '<div class="d-flex justify-content-between align-items-start mb-2">'
                      + '<h5 class="card-title mb-0">' + $('<div>').text(plugin.name || '').html() + '</h5>'
                      + '<span class="badge badge-info ml-2">' + $('<div>').text(plugin.type || '').html() + '</span>'
                      + '</div>'
                      + '<p class="card-text text-muted flex-grow-1">' + $('<div>').text(plugin.short_description || '').html() + '</p>'
                      + '<div class="d-flex justify-content-between align-items-center mt-3">'
                      + '<span class="font-weight-bold">' + price_label + '</span>'
                      + '<a href="/admin/marketplace/' + encodeURIComponent(plugin.slug || '') + '" class="btn btn-sm btn-primary">View</a>'
                      + '</div>'
                      + '</div></div></div>';
            });
            html += '</div>';

            $('#plugins-grid').html(html);
        });
    }

    $('#marketplace-search').on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(doSearch, 300);
    });

    $('#filter-type, #filter-price').on('change', function () {
        doSearch();
    });
});
</script>
@endsection
