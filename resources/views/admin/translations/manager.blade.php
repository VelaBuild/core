@extends('vela::layouts.admin')

@section('content')
@include('vela::admin.settings._page-head', ['subtitle' => __('Coverage across pages, articles, categories, and lang files. Click any cell to drill in and translate.')])

@php
    $surfaces = [
        'pages'      => ['label' => __('Pages'),       'icon' => 'fas fa-file'],
        'articles'   => ['label' => __('Articles'),    'icon' => 'fas fa-newspaper'],
        'categories' => ['label' => __('Categories'),  'icon' => 'fas fa-tags'],
        'lang_files' => ['label' => __('Lang files'),  'icon' => 'fas fa-code'],
    ];
@endphp

<div class="card">
    <div class="card-body">
        @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
        @if(session('error'))<div class="alert alert-warning">{{ session('error') }}</div>@endif

        <div class="mb-3 small text-muted">
            <i class="fas fa-info-circle mr-1"></i>
            {{ __('Source locale: :s. Add or remove languages under', ['s' => $source]) }}
            <a href="{{ route('vela.admin.settings.group', 'languages') }}">{{ __('Settings → Languages') }}</a>.
        </div>

        @if(empty($locales))
            <div class="alert alert-info mb-0">
                {{ __('Only one locale is enabled. Enable additional languages to see translation coverage.') }}
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>{{ __('Surface') }}</th>
                            @foreach($locales as $loc)
                                <th class="text-center">
                                    <strong>{{ strtoupper($loc) }}</strong>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($surfaces as $key => $meta)
                            <tr>
                                <td>
                                    <i class="{{ $meta['icon'] }} mr-2 text-primary"></i>
                                    <strong>{{ $meta['label'] }}</strong>
                                </td>
                                @foreach($locales as $loc)
                                    @php
                                        $row = $coverage[$key][$loc] ?? ['translated' => 0, 'total' => 0];
                                        $pct = $row['total'] > 0 ? round($row['translated'] / $row['total'] * 100) : 100;
                                        $missing = $row['total'] - $row['translated'];
                                        $cls = $pct === 100 ? 'success' : ($pct >= 50 ? 'warning' : 'danger');
                                    @endphp
                                    <td class="text-center">
                                        @if($row['total'] === 0)
                                            <span class="text-muted small">{{ __('No items') }}</span>
                                        @else
                                            <a href="{{ route('vela.admin.translations.drill', [$key, $loc]) }}" class="d-block text-decoration-none">
                                                <div class="progress" style="height: 6px;">
                                                    <div class="progress-bar bg-{{ $cls }}" style="width: {{ $pct }}%"></div>
                                                </div>
                                                <div class="small mt-1">
                                                    <strong>{{ $row['translated'] }}</strong> / {{ $row['total'] }}
                                                    @if($missing > 0)
                                                        <span class="badge badge-{{ $cls }} ml-1">{{ $missing }} {{ __('missing') }}</span>
                                                    @else
                                                        <i class="fas fa-check text-success"></i>
                                                    @endif
                                                </div>
                                            </a>
                                            @if($missing > 0)
                                                <form method="POST" action="{{ route('vela.admin.translations.translate.bulk') }}" class="mt-1" onsubmit="return confirm('{{ __('Translate :n items to :loc with AI?', ['n' => $missing, 'loc' => $loc]) }}')">
                                                    @csrf
                                                    <input type="hidden" name="surface" value="{{ $key }}">
                                                    <input type="hidden" name="locale" value="{{ $loc }}">
                                                    <input type="hidden" name="limit" value="50">
                                                    <button type="submit" class="btn btn-link btn-sm py-0">
                                                        <i class="fas fa-magic"></i> {{ __('Translate all') }}
                                                    </button>
                                                </form>
                                            @endif
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="alert alert-light border mt-3 mb-0 small">
                <i class="fas fa-terminal text-primary mr-2"></i>
                {{ __('Automate via CLI:') }}
                <code>php artisan vela:translate --locale=th --missing-only</code>
                — {{ __('see') }} <code>--help</code>.
            </div>
        @endif
    </div>
</div>
@endsection
