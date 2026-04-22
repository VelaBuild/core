@extends('vela::layouts.admin')
@section('breadcrumb', trans('vela::global.settings') . ' / ' . trans('vela::global.languages'))

@section('content')
@include('vela::admin.settings._page-head')

<div class="card">
    <div class="card-body">
        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="alert alert-warning">{{ session('error') }}</div>
        @endif

        <p class="text-muted mb-4">{{ trans('vela::global.languages_help') }}</p>

        <form action="{{ route('vela.admin.settings.updateGroup', 'languages') }}" method="POST">
            @csrf

            <div class="form-group">
                <label class="font-weight-bold">{{ trans('vela::global.primary_language') }}</label>
                <select name="primary_language" class="form-control" style="max-width: 300px;" id="primary-language-select">
                    @foreach($allLanguages as $code => $name)
                        <option value="{{ $code }}" {{ $primaryLanguage === $code ? 'selected' : '' }}>
                            {{ $name }} ({{ $code }})
                        </option>
                    @endforeach
                </select>
                <small class="form-text text-muted">{{ trans('vela::global.primary_language_help') }}</small>
            </div>

            <hr>

            <div class="form-group">
                <label class="font-weight-bold">{{ trans('vela::global.active_languages') }}</label>
                <small class="form-text text-muted mb-3">{{ trans('vela::global.active_languages_help') }}</small>

                @php
                    $localeFlags = ['en'=>"\u{1F1EC}\u{1F1E7}",'de'=>"\u{1F1E9}\u{1F1EA}",'ru'=>"\u{1F1F7}\u{1F1FA}",'fr'=>"\u{1F1EB}\u{1F1F7}",'nl'=>"\u{1F1F3}\u{1F1F1}",'it'=>"\u{1F1EE}\u{1F1F9}",'ar'=>"\u{1F1F8}\u{1F1E6}",'dk'=>"\u{1F1E9}\u{1F1F0}",'zh-Hans'=>"\u{1F1E8}\u{1F1F3}",'th'=>"\u{1F1F9}\u{1F1ED}"];
                @endphp

                <div class="row">
                    @foreach($allLanguages as $code => $name)
                        @php
                            $isActive = in_array($code, $activeLanguages);
                            $isPrimary = $primaryLanguage === $code;
                        @endphp
                        <div class="col-md-4 col-sm-6 mb-3">
                            <div class="card {{ $isActive ? 'border-success' : '' }}" style="margin-bottom: 0;">
                                <div class="card-body py-3 px-3 d-flex align-items-center justify-content-between">
                                    <div class="d-flex align-items-center">
                                        <span style="font-size: 1.5rem; margin-right: 10px;">{{ $localeFlags[$code] ?? "\u{1F310}" }}</span>
                                        <div>
                                            <strong>{{ $name }}</strong>
                                            <span class="text-muted ml-1">({{ $code }})</span>
                                            @if($isPrimary)
                                                <span class="badge badge-info ml-1">{{ trans('vela::global.primary') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox"
                                               class="custom-control-input lang-toggle"
                                               id="lang-{{ $code }}"
                                               name="active_languages[]"
                                               value="{{ $code }}"
                                               {{ $isActive ? 'checked' : '' }}
                                               {{ $isPrimary ? 'onclick="return false;"' : '' }}>
                                        <label class="custom-control-label" for="lang-{{ $code }}"></label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            @can('config_edit')
                <button type="submit" class="btn btn-primary">{{ trans('vela::global.save_settings') }}</button>
            @endcan
        </form>
    </div>
</div>
@endsection

@section('scripts')
@parent
<script>
document.getElementById('primary-language-select').addEventListener('change', function() {
    var primary = this.value;
    document.querySelectorAll('.lang-toggle').forEach(function(el) {
        el.removeAttribute('onclick');
        if (el.value === primary) {
            el.checked = true;
            el.setAttribute('onclick', 'return false;');
        }
    });
});
</script>
@endsection
