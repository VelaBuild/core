@extends('vela::layouts.admin')

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        @include('vela::admin.settings._nav')
    </div>
    <div class="card-body">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        <h5 class="mb-3">{{ trans('vela::global.global_custom_css') }}</h5>
        <p class="text-muted small">{{ trans('vela::global.global_custom_css_help') }}</p>
        <form action="{{ route('vela.admin.settings.updateGroup', 'customcss') }}" method="POST">
            @csrf
            <div class="form-group">
                <textarea name="custom_css_global" id="custom_css_global" rows="18" class="form-control" style="font-family: 'Courier New', Consolas, monospace; font-size: 13px; line-height: 1.5; tab-size: 4; background: #f8f9fa;">{{ $globalCss }}</textarea>
            </div>
            @can('config_edit')
                <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> {{ trans('vela::global.save_css') }}</button>
            @endcan
        </form>

        <hr>

        <h5 class="mb-3">{{ trans('vela::global.pages_with_custom_css_js') }}</h5>
        @if($pagesWithCss->isEmpty())
            <p class="text-muted">{{ trans('vela::global.no_pages_custom_css') }}</p>
        @else
        <table class="table table-sm table-bordered">
            <thead>
                <tr>
                    <th>{{ trans('vela::global.page_column_header') }}</th>
                    <th class="text-center" style="width:80px;">CSS</th>
                    <th class="text-center" style="width:80px;">JS</th>
                    <th style="width:80px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($pagesWithCss as $p)
                <tr>
                    <td>{{ $p->title }} <span class="text-muted small">/{{ $p->slug }}</span></td>
                    <td class="text-center">{!! $p->custom_css ? '<i class="fas fa-check text-success"></i>' : '<span class="text-muted">-</span>' !!}</td>
                    <td class="text-center">{!! $p->custom_js ? '<i class="fas fa-check text-success"></i>' : '<span class="text-muted">-</span>' !!}</td>
                    <td class="text-center">
                        <a href="{{ route('vela.admin.pages.edit', $p->id) }}" class="btn btn-sm btn-outline-primary">{{ trans('vela::global.edit') }}</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <p class="text-muted small">{{ trans('vela::global.per_page_css_help') }}</p>
        @endif
    </div>
</div>
@endsection
