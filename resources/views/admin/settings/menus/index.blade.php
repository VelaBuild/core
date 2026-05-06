@extends('vela::layouts.admin')

@section('content')
@include('vela::admin.settings._page-head', ['subtitle' => __('Menus declared by your active theme. Click one to edit its items.')])

<div class="card">
    <div class="card-body">
        @if(session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if(empty($rows))
            <div class="alert alert-info mb-0">
                {{ __('The active theme does not declare any menus.') }}
            </div>
        @else
            <div class="row">
                @foreach($rows as $row)
                    <div class="col-md-6 mb-3">
                        <div class="card h-100 {{ $row['orphaned'] ? 'border-warning' : '' }}">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="mb-1">
                                            <i class="fas fa-bars text-primary mr-2"></i>
                                            {{ $row['label'] }}
                                            @if($row['orphaned'])
                                                <span class="badge badge-warning ml-2">{{ __('Not in active theme') }}</span>
                                            @endif
                                        </h5>
                                        <code class="small text-muted">{{ $row['slot'] }}</code>
                                        @if($row['description'])
                                            <p class="text-muted mb-2 mt-2">{{ $row['description'] }}</p>
                                        @endif
                                    </div>
                                    <div class="text-right">
                                        @if($row['item_count'] === null)
                                            <span class="badge badge-light">{{ __('Default') }}</span>
                                        @else
                                            <span class="badge badge-info">{{ trans_choice(':count item|:count items', $row['item_count'], ['count' => $row['item_count']]) }}</span>
                                        @endif
                                    </div>
                                </div>

                                @if($row['auto_add_pages'])
                                    <div class="small text-muted mt-2">
                                        <i class="fas fa-magic"></i> {{ __('Auto-adds new pages') }}
                                    </div>
                                @endif

                                <div class="mt-3">
                                    <a href="{{ route('vela.admin.settings.menus.edit', $row['slot']) }}" class="btn btn-primary btn-sm">
                                        <i class="fas fa-edit"></i> {{ __('Edit') }}
                                    </a>
                                    @if($row['item_count'] !== null)
                                        <form action="{{ route('vela.admin.settings.menus.destroy', $row['slot']) }}" method="POST" class="d-inline" onsubmit="return confirm('{{ __('Reset this menu to defaults?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-link btn-sm text-danger">{{ __('Reset to defaults') }}</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="alert alert-light border mt-3 mb-0">
            <i class="fas fa-info-circle text-primary mr-2"></i>
            {{ __('Slots are declared by your theme. To add a new menu location, edit your theme’s template.json and declare it under the "menus" key.') }}
        </div>
    </div>
</div>
@endsection
