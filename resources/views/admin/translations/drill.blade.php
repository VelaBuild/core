@extends('vela::layouts.admin')

@section('content')
@include('vela::admin.settings._page-head', ['subtitle' => __('Items missing translation in :surface for :locale.', ['surface' => $surface, 'locale' => $locale])])

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="fas fa-language mr-2"></i>
            {{ __(':surface — :locale', ['surface' => ucfirst(str_replace('_', ' ', $surface)), 'locale' => strtoupper($locale)]) }}
        </span>
        <a href="{{ route('vela.admin.translations.manager') }}" class="btn btn-link btn-sm">
            <i class="fas fa-arrow-left"></i> {{ __('Back to dashboard') }}
        </a>
    </div>
    <div class="card-body">
        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if(session('error'))<div class="alert alert-warning">{{ session('error') }}</div>@endif

        @if(empty($missing))
            <div class="alert alert-success mb-0">
                <i class="fas fa-check-circle mr-2"></i> {{ __('Nothing missing — everything is translated.') }}
            </div>
        @else
            <p class="text-muted small">
                {{ trans_choice(':count item missing|:count items missing', count($missing), ['count' => count($missing)]) }}.
                {{ __('Click "Translate" to use AI on a single row, or "Translate all" on the dashboard for bulk.') }}
            </p>
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>{{ __('Item') }}</th>
                        <th>{{ __('Reason') }}</th>
                        <th class="text-right"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($missing as $item)
                        <tr>
                            <td><code class="small">{{ $item['label'] }}</code></td>
                            <td class="small text-muted">{{ $item['reason'] }}</td>
                            <td class="text-right">
                                <form method="POST" action="{{ route('vela.admin.translations.translate') }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="surface" value="{{ $surface }}">
                                    <input type="hidden" name="locale" value="{{ $locale }}">
                                    <input type="hidden" name="id" value="{{ $item['id'] }}">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="fas fa-magic"></i> {{ __('Translate') }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
