@extends('vela::layouts.admin')

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

        {{-- A theme swap changes the layout and the stylesheet; the homepage
             keeps whatever rows and blocks it already had. Without saying so,
             the change reads as a theme that only replaced the header and
             footer — so offer the step that installs this theme's own
             homepage, next to a plain statement of what just happened. --}}
        @if(session('theme_switched_to') && isset($templates[session('theme_switched_to')]))
            @php $velaSwitched = session('theme_switched_to'); @endphp
            <div class="alert alert-info d-flex align-items-center justify-content-between flex-wrap">
                <div class="mr-3">
                    {{ trans('vela::global.theme_switched_layout_only', ['theme' => __($templates[$velaSwitched]['label'])]) }}
                </div>
                <form action="{{ route('vela.admin.settings.appearance.installHomepage') }}" method="POST" class="d-inline"
                      onsubmit="return confirm('{{ trans('vela::global.install_homepage_confirm_replace') }}');">
                    @csrf
                    <input type="hidden" name="template" value="{{ $velaSwitched }}">
                    <input type="hidden" name="mode" value="replace">
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="fas fa-home"></i> {{ trans('vela::global.install_as_homepage') }}
                    </button>
                </form>
            </div>
        @endif

        {{-- The same offer, but standing still. The alert above appears once,
             in the request that switched the theme; a reload loses it and
             there is then no way to a theme's own homepage at all, because you
             cannot switch to the theme you are already using. Somebody looking
             for "the default layout" has to be able to find it. --}}
        @if($hasHomeTemplate && !session('theme_switched_to'))
        @can('config_edit')
        <div class="alert alert-light border d-flex align-items-center justify-content-between flex-wrap">
            <div class="mr-3">
                {{ trans('vela::global.home_template_available', ['theme' => __($templates[$activeTemplate]['label'] ?? $activeTemplate)]) }}
            </div>
            <form action="{{ route('vela.admin.settings.appearance.installHomepage') }}" method="POST" class="d-inline"
                  onsubmit="return confirm('{{ trans('vela::global.install_homepage_confirm_replace') }}');">
                @csrf
                <input type="hidden" name="template" value="{{ $activeTemplate }}">
                <input type="hidden" name="mode" value="replace">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-home"></i> {{ trans('vela::global.install_as_homepage') }}
                </button>
            </form>
        </div>
        @endcan
        @endif

        <!-- Theme Picker -->
        <h5 class="mb-3">{{ trans('vela::global.theme') }}</h5>
        <form action="{{ route('vela.admin.settings.updateGroup', 'appearance') }}" method="POST" id="theme-form">
            @csrf
            <div class="row mb-4">
                @foreach($templates as $slug => $template)
                <div class="col-md-4 col-lg-3 mb-3">
                    {{-- A click anywhere on the card used to swap the live
                         site's theme on the spot, which made a near miss on the
                         Preview button below a real theme switch. Ask first. --}}
                    <div class="card h-100 {{ ($settings['active_template'] ?? 'default') === $slug ? 'border-primary' : '' }}" style="cursor:pointer;" onclick="velaSwitchTheme('{{ $slug }}', @js(__($template['label'])));">
                        <div class="position-relative">
                            @if(!empty($template['screenshot']) && file_exists(public_path($template['screenshot'])))
                            <img src="{{ asset($template['screenshot']) }}" class="card-img-top" alt="{{ __($template['label']) }}" style="height:180px; object-fit:cover;">
                            @else
                            <div class="card-img-top bg-light d-flex align-items-center justify-content-center" style="height:180px;">
                                <i class="fas fa-palette fa-3x text-muted"></i>
                            </div>
                            @endif
                            @if(($settings['active_template'] ?? 'default') === $slug)
                            <span class="badge badge-primary position-absolute" style="top:8px;right:8px;">{{ trans('vela::global.active_badge') }}</span>
                            @endif
                        </div>
                        <div class="card-body p-2 text-center">
                            <input type="radio" name="active_template" id="theme-{{ $slug }}" value="{{ $slug }}" {{ ($settings['active_template'] ?? 'default') === $slug ? 'checked' : '' }} class="d-none">
                            <strong>{{ __($template['label']) }}</strong>
                            @if(!empty($template['description']))
                            <p class="text-muted small mb-0">{{ __($template['description']) }}</p>
                            @endif
                        </div>
                        <div class="card-footer p-1 text-center">
                            @php
                                // The full-height capture sits beside the card
                                // image under the same name; vela:generate-theme-
                                // screenshots writes both.
                                $velaFullShot = !empty($template['screenshot'])
                                    ? preg_replace('/(\.[a-z]+)$/i', '-full$1', $template['screenshot'])
                                    : null;
                                $velaHasFullShot = $velaFullShot && file_exists(public_path($velaFullShot));
                            @endphp
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    onclick="event.stopPropagation(); openPreview('{{ route('vela.admin.settings.appearance.preview', $slug) }}', '{{ __($template['label']) }}', '{{ $velaHasFullShot ? asset($velaFullShot) : '' }}');">
                                <i class="fas fa-eye"></i> {{ trans('vela::global.preview') }}
                            </button>
                            @php
                                // Only themes written into this site can go. The
                                // ones that ship with Vela live in the package and
                                // would come back with the next update; the one in
                                // use would take the site's layout with it.
                                $velaOwnTheme = is_dir(resource_path('views/templates/' . $slug));
                                $velaInUse    = ($settings['active_template'] ?? 'default') === $slug;
                            @endphp
                            @if($velaOwnTheme && !$velaInUse)
                            @can('config_edit')
                            {{-- The card sits inside the theme-switching form, and a
                                 form inside a form is dropped by the browser. The
                                 button stays here and belongs to a form further down
                                 the page by name. --}}
                            <button type="submit" form="vela-delete-theme-{{ $slug }}"
                                    class="btn btn-sm btn-outline-danger" title="Delete this theme"
                                    onclick="event.stopPropagation();">
                                <i class="fas fa-trash"></i>
                            </button>
                            @endcan
                            @endif
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </form>

        @can('config_edit')
        @foreach($templates as $slug => $template)
            @if(is_dir(resource_path('views/templates/' . $slug)) && ($settings['active_template'] ?? 'default') !== $slug)
            <form id="vela-delete-theme-{{ $slug }}" method="POST"
                  action="{{ route('vela.admin.settings.appearance.deleteTheme') }}"
                  onsubmit="return confirm('Delete the {{ __($template['label']) }} theme? A copy is kept in storage, but it is gone from here.');">
                @csrf
                <input type="hidden" name="template" value="{{ $slug }}">
            </form>
            @endif
        @endforeach
        @endcan

        <!-- Install Homepage -->
        <hr>
        <h5 class="mb-3">{{ trans('vela::global.install_homepage') }}</h5>
        <p class="text-muted mb-3">{{ trans('vela::global.install_homepage_desc') }}</p>
        <div class="row mb-4">
            @foreach($templates as $slug => $template)
                @if(!empty($template['path']) && file_exists(($template['path'] ?? '') . '/home-template.json'))
                <div class="col-md-4 col-lg-3 mb-3">
                    <div class="card h-100">
                        <div class="card-body text-center p-3">
                            <strong>{{ __($template['label']) }}</strong>
                            <div class="mt-2">
                                <form action="{{ route('vela.admin.settings.appearance.installHomepage') }}" method="POST" class="d-inline" onsubmit="return confirm('{{ trans('vela::global.install_homepage_confirm_replace') }}');">
                                    @csrf
                                    <input type="hidden" name="template" value="{{ $slug }}">
                                    <input type="hidden" name="mode" value="replace">
                                    <button type="submit" class="btn btn-sm btn-warning">
                                        <i class="fas fa-home"></i> {{ trans('vela::global.install_as_homepage') }}
                                    </button>
                                </form>
                            </div>
                            <div class="mt-1">
                                <form action="{{ route('vela.admin.settings.appearance.installHomepage') }}" method="POST" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="template" value="{{ $slug }}">
                                    <input type="hidden" name="mode" value="new_page">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-plus"></i> {{ trans('vela::global.install_as_new_page') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                @endif
            @endforeach
        </div>

        <!-- Theme Options -->
        @if(!empty($themeOptions))
        <hr>
        <h5 class="mb-3">{{ trans('vela::global.theme_options') }}</h5>
        <form action="{{ route('vela.admin.settings.updateGroup', 'appearance') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="active_template" value="{{ $settings['active_template'] ?? 'default' }}">
            <input type="hidden" name="_theme_options" value="1">

            @php $groups = collect($themeOptions)->groupBy('group'); @endphp
            @foreach($groups as $groupName => $options)
            <div class="card mb-3">
                <div class="card-header py-2"><strong>{{ __($groupName) ?: trans('vela::global.general') }}</strong></div>
                <div class="card-body">
                    @foreach($options as $optKey => $opt)
                        @php $formKey = 'theme_' . $optKey; $currentVal = $themeValues[$formKey] ?? ''; @endphp

                        @if($opt['type'] === 'image')
                        <div class="form-group">
                            <label>{{ __($opt['label']) }}</label>
                            <div class="d-flex align-items-center mb-2">
                                @if($currentVal)
                                    <img src="{{ asset($currentVal) }}" alt="{{ __($opt['label']) }}" style="height:60px; width:auto; max-width:200px; object-fit:contain; border:1px solid #dee2e6; border-radius:4px; margin-right:12px;">
                                @elseif(!empty($opt['default']))
                                    <img src="{{ asset($opt['default']) }}" alt="{{ __($opt['label']) }} (default)" style="height:60px; width:auto; max-width:200px; object-fit:contain; border:1px solid #dee2e6; border-radius:4px; margin-right:12px; opacity:0.5;">
                                    <span class="text-muted small mr-3">{{ trans('vela::global.default_label') }}</span>
                                @endif
                            </div>
                            <input type="file" class="form-control-file" name="{{ $formKey }}" accept="image/*">
                            <small class="form-text text-muted">{{ trans('vela::global.keep_current_image', ['state' => $currentVal ? trans('vela::global.content') : trans('vela::global.default_label')]) }}</small>
                        </div>

                        @elseif($opt['type'] === 'toggle')
                        <div class="form-group">
                            <div class="custom-control custom-switch">
                                <input type="hidden" name="{{ $formKey }}" value="0">
                                <input type="checkbox" class="custom-control-input" id="{{ $formKey }}" name="{{ $formKey }}" value="1"
                                    {{ ($currentVal !== '' ? $currentVal : ($opt['default'] ? '1' : '0')) === '1' ? 'checked' : '' }}>
                                <label class="custom-control-label" for="{{ $formKey }}">{{ __($opt['label']) }}</label>
                            </div>
                        </div>

                        @elseif($opt['type'] === 'color')
                        <div class="form-group">
                            <label for="{{ $formKey }}">{{ __($opt['label']) }}</label>
                            <div class="d-flex align-items-center">
                                <input type="color" class="mr-2" name="{{ $formKey }}" id="{{ $formKey }}" value="{{ $currentVal ?: $opt['default'] }}" style="width:50px; height:36px; padding:2px; border:1px solid #ced4da; border-radius:4px; cursor:pointer;">
                                <code class="text-muted small" id="{{ $formKey }}_hex">{{ $currentVal ?: $opt['default'] }}</code>
                                @if($currentVal && $currentVal !== $opt['default'])
                                    <span class="ml-2 text-muted small">({{ trans('vela::global.default_value', ['value' => $opt['default']]) }})</span>
                                @endif
                            </div>
                        </div>

                        @elseif($opt['type'] === 'textarea')
                        <div class="form-group">
                            <label for="{{ $formKey }}">{{ __($opt['label']) }}</label>
                            <textarea class="form-control" name="{{ $formKey }}" id="{{ $formKey }}" rows="3">{{ $currentVal }}</textarea>
                        </div>

                        @else
                        <div class="form-group">
                            <label for="{{ $formKey }}">{{ __($opt['label']) }}</label>
                            <input type="text" class="form-control" name="{{ $formKey }}" id="{{ $formKey }}" value="{{ $currentVal }}" placeholder="{{ $opt['default'] ?? '' }}">
                        </div>
                        @endif
                    @endforeach
                </div>
            </div>
            @endforeach

            @can('config_edit')
                <button type="submit" class="btn btn-primary">{{ trans('vela::global.save_theme_options') }}</button>
            @endcan
        </form>
        @endif

    </div>
</div>

<!-- Theme Preview Modal -->
<div class="modal fade" id="themePreviewModal" tabindex="-1" role="dialog" aria-labelledby="themePreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document" style="max-width:90vw;">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="themePreviewModalLabel">{{ trans('vela::global.theme_preview') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body p-0" style="max-height:80vh; overflow-y:auto; background:#f1f3f5;">
                {{-- The whole homepage as one image, so the modal shows the
                     theme end to end the moment it opens. The live frame is a
                     click away for anyone who wants to move around in it. --}}
                <img id="themePreviewShot" src="" alt="" style="width:100%; display:none;">
                <iframe id="themePreviewFrame" src="about:blank" style="width:100%; height:75vh; border:none; display:none;"></iframe>
            </div>
            <div class="modal-footer py-1">
                <button type="button" id="themePreviewLive" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-external-link-alt"></i> {{ trans('vela::global.preview') }} — live
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
var themePreviewUrl = '';
var velaActiveTheme = @js($settings['active_template'] ?? 'default');
var velaSwitchConfirm = @js(trans('vela::global.theme_switch_confirm', ['theme' => ':theme']));

function velaSwitchTheme(slug, label) {
    if (slug === velaActiveTheme) {
        return;
    }
    if (! confirm(velaSwitchConfirm.replace(':theme', label))) {
        return;
    }
    document.getElementById('theme-' + slug).checked = true;
    document.getElementById('theme-form').submit();
}

function openPreview(url, label, shot) {
    var frame = document.getElementById('themePreviewFrame');
    var img = document.getElementById('themePreviewShot');
    var liveBtn = document.getElementById('themePreviewLive');

    themePreviewUrl = url;
    document.getElementById('themePreviewModalLabel').textContent = label + ' — Preview';

    // Prefer the captured page: it is one request and shows the theme whole.
    // A theme with no capture yet still gets the live frame it always had.
    if (shot) {
        img.src = shot;
        img.alt = label;
        img.style.display = '';
        frame.style.display = 'none';
        frame.src = 'about:blank';
        liveBtn.style.display = '';
    } else {
        showLivePreview();
    }

    $('#themePreviewModal').modal('show');
}

function showLivePreview() {
    var frame = document.getElementById('themePreviewFrame');
    document.getElementById('themePreviewShot').style.display = 'none';
    document.getElementById('themePreviewLive').style.display = 'none';
    frame.style.display = '';
    frame.src = themePreviewUrl;
}

document.getElementById('themePreviewLive').addEventListener('click', showLivePreview);

$('#themePreviewModal').on('hidden.bs.modal', function () {
    document.getElementById('themePreviewFrame').src = 'about:blank';
    document.getElementById('themePreviewShot').src = '';
});
</script>
@endsection
