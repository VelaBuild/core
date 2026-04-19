<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Vela CMS') }} — Admin</title>
    <link rel="icon" type="image/png" href="{{ asset('vendor/vela/images/vela-icon.png') }}">
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.css" rel="stylesheet" />
    <link href="https://use.fontawesome.com/releases/v5.2.0/css/all.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/1.10.19/css/jquery.dataTables.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/1.10.19/css/dataTables.bootstrap4.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/buttons/1.2.4/css/buttons.dataTables.min.css" rel="stylesheet" />
    <link href="https://cdn.datatables.net/select/1.3.0/css/select.dataTables.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.5/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.47/css/bootstrap-datetimepicker.min.css" rel="stylesheet" />
    <link href="https://unpkg.com/@coreui/coreui@3.2/dist/css/coreui.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.5.1/min/dropzone.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/jquery.perfect-scrollbar/1.5.0/css/perfect-scrollbar.min.css" rel="stylesheet" />
    <link href="{{ asset('vendor/vela/css/vela-admin.css') }}" rel="stylesheet" />
    <link href="{{ asset('vendor/vela/css/custom.css') }}?v={{ filemtime(public_path('vendor/vela/css/custom.css')) }}" rel="stylesheet" />
    @yield('styles')
</head>

<body class="c-app vela-admin">
    {{-- New Vela Sidebar --}}
    @include('vela::partials.menu')

    {{-- Main content area --}}
    <div class="vela-main-wrapper">
        {{-- Topbar --}}
        <header class="vela-topbar">
            <button class="vela-sidebar-toggle" type="button" onclick="document.getElementById('vela-sidebar').classList.toggle('is-open')">
                <i class="fas fa-bars"></i>
            </button>

            <nav class="breadcrumb">
                <span>{{ config('app.name') }}</span>
                <span class="sep">/</span>
                <span class="cur">@yield('breadcrumb', trans('vela::global.dashboard'))</span>
            </nav>

            <div class="vela-search-bar">
                <span class="search-ico"><i class="fas fa-search"></i></span>
                <input placeholder="{{ trans('vela::global.search') }}..." id="vela-cmd-trigger">
                <span class="kbd-hint"><span class="vela-kbd">⌘K</span></span>
            </div>

            <div class="vela-topbar-actions">
                @php
                    $localeFlags = ['en'=>"\u{1F1EC}\u{1F1E7}",'de'=>"\u{1F1E9}\u{1F1EA}",'ru'=>"\u{1F1F7}\u{1F1FA}",'fr'=>"\u{1F1EB}\u{1F1F7}",'nl'=>"\u{1F1F3}\u{1F1F1}",'it'=>"\u{1F1EE}\u{1F1F9}",'ar'=>"\u{1F1F8}\u{1F1E6}",'dk'=>"\u{1F1E9}\u{1F1F0}",'zh-Hans'=>"\u{1F1E8}\u{1F1F3}",'th'=>"\u{1F1F9}\u{1F1ED}"];
                @endphp

                @can('config_edit')
                <div class="dropdown">
                    <button class="vela-btn vela-btn-ghost vela-btn-sm" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="{{ trans('vela::global.refresh_cache') }}">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right vela-dropdown-styled">
                        <h6 class="dropdown-header">
                            <i class="fas fa-sync-alt mr-1"></i> {{ trans('vela::global.refresh_cache') }}
                        </h6>
                        <form method="POST" action="{{ route('vela.admin.cache.clear') }}">
                            @csrf
                            <button type="submit" class="dropdown-item vela-dropdown-item-danger"><i class="fas fa-sync-alt fa-fw mr-2"></i> {{ trans('vela::global.everything') }}</button>
                        </form>
                        <div class="dropdown-divider"></div>
                        <form method="POST" action="{{ route('vela.admin.cache.clear-home') }}">
                            @csrf
                            <button type="submit" class="dropdown-item"><i class="fas fa-home fa-fw mr-2"></i> {{ trans('vela::global.home') }}</button>
                        </form>
                        <form method="POST" action="{{ route('vela.admin.cache.clear-pages') }}">
                            @csrf
                            <button type="submit" class="dropdown-item"><i class="fas fa-file fa-fw mr-2"></i> {{ trans('vela::global.pages') }}</button>
                        </form>
                        <form method="POST" action="{{ route('vela.admin.cache.clear-articles') }}">
                            @csrf
                            <button type="submit" class="dropdown-item"><i class="fas fa-newspaper fa-fw mr-2"></i> {{ trans('vela::global.articles') }}</button>
                        </form>
                        <form method="POST" action="{{ route('vela.admin.cache.clear-images') }}">
                            @csrf
                            <button type="submit" class="dropdown-item"><i class="fas fa-images fa-fw mr-2"></i> {{ trans('vela::global.images') }}</button>
                        </form>
                        <form method="POST" action="{{ route('vela.admin.cache.clear-pwa') }}">
                            @csrf
                            <button type="submit" class="dropdown-item"><i class="fas fa-mobile-alt fa-fw mr-2"></i> {{ trans('vela::global.app_install') }}</button>
                        </form>
                    </div>
                </div>
                @endcan

                @php
                    $allLangs = config('vela.available_languages', []);
                    $activeJson = \VelaBuild\Core\Models\VelaConfig::where('key', 'active_languages')->value('value');
                    $activeLangs = $activeJson ? json_decode($activeJson, true) : array_keys($allLangs);
                    $visibleLangs = array_intersect_key($allLangs, array_flip($activeLangs));
                @endphp
                @if(count($visibleLangs) > 1)
                <div class="dropdown">
                    <button class="vela-btn vela-btn-ghost vela-btn-sm" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <span class="vela-flag">{{ $localeFlags[app()->getLocale()] ?? "\u{1F310}" }}</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right vela-dropdown-styled">
                        <h6 class="dropdown-header">
                            <i class="fas fa-globe mr-1"></i> {{ trans('vela::global.language') }}
                        </h6>
                        @foreach($visibleLangs as $langLocale => $langName)
                            <a class="dropdown-item {{ app()->getLocale() === $langLocale ? 'active' : '' }}" href="{{ url()->current() }}?change_language={{ $langLocale }}">
                                <span class="vela-flag mr-2">{{ $localeFlags[$langLocale] ?? "\u{1F310}" }}</span> {{ $langName }}
                                @if(app()->getLocale() === $langLocale)
                                    <i class="fas fa-check ml-auto text-success"></i>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
                @endif

                <a href="{{ url('/') }}" target="_blank" class="vela-btn vela-btn-secondary vela-btn-sm" title="{{ trans('vela::global.visit_site') }}">
                    {{ trans('vela::global.visit_site') }} <i class="fas fa-external-link-alt ml-1"></i>
                </a>

                {{-- Profile dropdown --}}
                <div class="dropdown">
                    <button class="vela-btn vela-btn-ghost vela-btn-sm" data-toggle="dropdown" style="gap:8px;">
                        <img src="{{ auth('vela')->user()->getAvatarUrl(32) }}" alt="{{ auth('vela')->user()->name }}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">
                        <span class="d-md-down-none">{{ auth('vela')->user()->name }}</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right vela-dropdown-styled pt-0">
                        <div class="dropdown-header bg-light py-2">
                            <strong>{{ auth('vela')->user()->name }}</strong><br>
                            <small class="text-muted">{{ auth('vela')->user()->email }}</small>
                        </div>
                        @foreach(app(\VelaBuild\Core\Vela::class)->profileMenu()->all() as $itemName => $item)
                            @if($item['divider_before'])
                                <div class="dropdown-divider"></div>
                            @endif
                            @if($item['gate'])
                                @can($item['gate'])
                                    @if($item['route'] === '#logout')
                                        <a class="dropdown-item" href="#" onclick="event.preventDefault(); document.getElementById('logoutform').submit();">
                                            <i class="{{ $item['icon'] }} mr-2"></i> {{ trans($item['label']) }}
                                        </a>
                                    @else
                                        <a class="dropdown-item" href="{{ route($item['route']) }}">
                                            <i class="{{ $item['icon'] }} mr-2"></i> {{ trans($item['label']) }}
                                        </a>
                                    @endif
                                @endcan
                            @else
                                @if($item['route'] === '#logout')
                                    <a class="dropdown-item" href="#" onclick="event.preventDefault(); document.getElementById('logoutform').submit();">
                                        <i class="{{ $item['icon'] }} mr-2"></i> {{ trans($item['label']) }}
                                    </a>
                                @else
                                    <a class="dropdown-item" href="{{ route($item['route']) }}">
                                        <i class="{{ $item['icon'] }} mr-2"></i> {{ trans($item['label']) }}
                                    </a>
                                @endif
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        </header>

        {{-- Main content --}}
        <main class="vela-content">
            @if(session('message'))
                <div class="alert alert-success" role="alert">{{ session('message') }}</div>
            @endif
            @if($errors->count() > 0)
                <div class="alert alert-danger">
                    <ul class="list-unstyled mb-0">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @yield('content')
        </main>

        <form id="logoutform" action="{{ route('vela.auth.logout') }}" method="POST" style="display: none;">
            {{ csrf_field() }}
        </form>
    </div>

    {{-- Command Palette --}}
    @include('vela::partials.command-palette')

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.perfect-scrollbar/1.5.0/perfect-scrollbar.min.js"></script>
    <script src="https://unpkg.com/@coreui/coreui@3.2/dist/js/coreui.bundle.min.js"></script>
    <script src="//cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.10.19/js/dataTables.bootstrap4.min.js"></script>
    <script src="//cdn.datatables.net/buttons/1.2.4/js/dataTables.buttons.min.js"></script>
    <script src="//cdn.datatables.net/buttons/1.2.4/js/buttons.flash.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.2.4/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.2.4/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/1.2.4/js/buttons.colVis.min.js"></script>
    <script src="https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/pdfmake.min.js"></script>
    <script src="https://cdn.rawgit.com/bpampuch/pdfmake/0.1.18/build/vfs_fonts.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/jszip/2.5.0/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/select/1.3.0/js/dataTables.select.min.js"></script>
    <script src="https://cdn.ckeditor.com/ckeditor5/16.0.0/classic/ckeditor.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.22.2/moment.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.17.47/js/bootstrap-datetimepicker.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.5/js/select2.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.5.1/min/dropzone.min.js"></script>
    <script src="{{ asset('vendor/vela/js/main.js') }}"></script>
    <script>
        $(function() {
  let copyButtonTrans = '{{ trans('vela::global.datatables.copy') }}'
  let csvButtonTrans = '{{ trans('vela::global.datatables.csv') }}'
  let excelButtonTrans = '{{ trans('vela::global.datatables.excel') }}'
  let pdfButtonTrans = '{{ trans('vela::global.datatables.pdf') }}'
  let printButtonTrans = '{{ trans('vela::global.datatables.print') }}'
  let colvisButtonTrans = '{{ trans('vela::global.datatables.colvis') }}'
  let selectAllButtonTrans = '{{ trans('vela::global.select_all') }}'
  let selectNoneButtonTrans = '{{ trans('vela::global.deselect_all') }}'

  let languages = {
    'en': 'https://cdn.datatables.net/plug-ins/1.10.19/i18n/English.json',
        'de': 'https://cdn.datatables.net/plug-ins/1.10.19/i18n/German.json',
        'ru': 'https://cdn.datatables.net/plug-ins/1.10.19/i18n/Russian.json',
        'fr': 'https://cdn.datatables.net/plug-ins/9dcbecd42ad/i18n/French.json',
        'nl': 'https://cdn.datatables.net/plug-ins/1.10.19/i18n/Dutch.json',
        'it': 'https://cdn.datatables.net/plug-ins/1.10.19/i18n/Italian.json',
        'ar': 'https://cdn.datatables.net/plug-ins/1.10.19/i18n/Arabic.json',
        'dk': 'https://cdn.datatables.net/plug-ins/1.10.19/i18n/Danish.json',
        'zh-Hans': 'https://cdn.datatables.net/plug-ins/1.10.19/i18n/Chinese.json',
        'th': 'https://cdn.datatables.net/plug-ins/1.10.19/i18n/Thai.json'
  };

  $.extend(true, $.fn.dataTable.Buttons.defaults.dom.button, { className: 'btn' })
  $.extend(true, $.fn.dataTable.defaults, {
    language: {
      url: languages['{{ app()->getLocale() }}']
    },
    columnDefs: [{
        orderable: false,
        className: 'select-checkbox',
        targets: 0
    }, {
        orderable: false,
        searchable: false,
        targets: -1
    }],
    select: {
      style:    'multi+shift',
      selector: 'td:first-child'
    },
    order: [],
    scrollX: true,
    pageLength: 100,
    dom: 'lBfrtip<"actions">',
    buttons: [
      {
        extend: 'selectAll',
        className: 'btn-primary',
        text: selectAllButtonTrans,
        exportOptions: {
          columns: ':visible'
        },
        action: function(e, dt) {
          e.preventDefault()
          dt.rows().deselect();
          dt.rows({ search: 'applied' }).select();
        }
      },
      {
        extend: 'selectNone',
        className: 'btn-primary',
        text: selectNoneButtonTrans,
        exportOptions: {
          columns: ':visible'
        }
      },
      {
        extend: 'colvis',
        className: 'btn-default',
        text: colvisButtonTrans,
        exportOptions: {
          columns: ':visible'
        }
      }
    ]
  });

  $.fn.dataTable.ext.classes.sPageButton = '';
});

    </script>
    @stack('vela-page-editor-blocks')
    @yield('scripts')
    @stack('scripts')
    @can('ai_chat_access')
        <div id="ai-chat-toggle" style="position:fixed;right:0;top:50%;transform:translateY(-50%);writing-mode:vertical-rl;text-orientation:mixed;background:linear-gradient(180deg, var(--vela-teal-400), var(--vela-teal-600));color:#fff;padding:12px 6px;border-radius:8px 0 0 8px;cursor:pointer;font-size:13px;font-weight:600;letter-spacing:1px;z-index:1050;box-shadow:-2px 0 8px rgba(0,0,0,.15);transition:background .2s;">{{ trans('vela::ai.helper_title') }}</div>
        @include('vela::partials.ai-chatbot')
        <link href="{{ asset('vendor/vela/css/ai-chatbot.css') }}" rel="stylesheet" />
        <script src="{{ asset('vendor/vela/js/ai-chatbot.js') }}"></script>
    @endcan
</body>

</html>
