@extends('vela::layouts.admin')

@section('styles')
@include('vela::admin.partials.editor-styles')
@endsection

@section('content')
<div class="content-editor-page">
    <form method="POST" action="{{ route('vela.admin.pages.update', [$page->id]) }}" enctype="multipart/form-data" id="page-form">
        @method('PUT')
        @csrf
        <input type="hidden" name="rows" id="rows-json" value="[]">

        {{-- Page Header --}}
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1" style="font-weight:700; color:#111827;">{{ trans('vela::global.edit_page') }}</h4>
                <span class="text-muted" style="font-size:0.85rem;">
                    {{ trans('vela::global.last_updated') }} {{ $page->updated_at->diffForHumans() }}
                    <span title="{{ $page->updated_at->format('jS M Y g:i a') }}"><i class="fas fa-clock" style="font-size:0.75rem;"></i></span>
                </span>
            </div>
            <div class="d-flex" style="gap:8px;">
                @if($page->status === 'published')
                    <a href="{{ url('/' . $page->slug) }}" target="_blank" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-external-link-alt mr-1"></i> {{ trans('vela::global.view') }}
                    </a>
                @endif
                <a href="{{ route('vela.admin.pages.index') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left mr-1"></i> {{ trans('vela::global.back') }}
                </a>
            </div>
        </div>

        <div class="row">
            {{-- Main Content Area --}}
            <div class="col-lg-8">
                {{-- Title & Slug --}}
                <div class="section-card">
                    <div class="section-body">
                        <div class="form-group">
                            <input class="form-control title-input {{ $errors->has('title') ? 'is-invalid' : '' }}" type="text" name="title" id="title" value="{{ old('title', $page->title) }}" required placeholder="{{ trans('vela::global.page_title_placeholder') }}">
                            @if($errors->has('title'))
                                <div class="invalid-feedback">{{ $errors->first('title') }}</div>
                            @endif
                        </div>
                        <div class="form-group">
                            <div class="input-group input-group-sm">
                                <div class="input-group-prepend">
                                    <span class="input-group-text" style="background:#f9fafb; border-color:#e5e7eb; color:#9ca3af; font-size:0.8rem;">slug</span>
                                </div>
                                <input class="form-control form-control-sm {{ $errors->has('slug') ? 'is-invalid' : '' }}" type="text" name="slug" id="slug" value="{{ old('slug', $page->slug) }}" required style="border-color:#e5e7eb; font-size:0.85rem;">
                            </div>
                            @if(($page->slug ?? '') === 'home' || !\VelaBuild\Core\Models\Page::where('slug', 'home')->exists())
                                <span class="help-block text-info mt-1"><i class="fas fa-info-circle"></i> {!! trans('vela::global.homepage_slug_help') !!}</span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Page Content (Block Editor) --}}
                <div class="section-card">
                    <div class="section-header"><i class="fas fa-cubes"></i> {{ trans('vela::global.page_content') }}</div>
                    <div class="section-body">
                        @include('vela::admin.pages.partials.block-editor')
                    </div>
                </div>
            </div>

            {{-- Sidebar --}}
            <div class="col-lg-4">
                <div class="sticky-panel">
                    {{-- Publish --}}
                    <div class="section-card">
                        <div class="section-header"><i class="fas fa-paper-plane"></i> {{ trans('vela::global.publish') }}</div>
                        <div class="section-body">
                            <div class="form-group">
                                <label>{{ trans('vela::global.status') }}</label>
                                <select class="form-control" name="status" id="status" required>
                                    @foreach(\VelaBuild\Core\Models\Page::STATUS_SELECT as $key => $label)
                                        <option value="{{ $key }}" {{ old('status', $page->status) === $key ? 'selected' : '' }}>{{ trans('vela::global.status_' . $key) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <input type="hidden" name="locale" value="{{ $page->locale }}">
                            <button class="btn publish-btn btn-block" type="submit" id="page-save-btn">
                                <i class="fas fa-check mr-1"></i> {{ trans('vela::global.save_page') }}
                            </button>
                        </div>
                    </div>

                    {{-- Page Settings --}}
                    <div class="section-card">
                        <div class="section-header"><i class="fas fa-sitemap"></i> {{ trans('vela::global.page_settings') }}</div>
                        <div class="section-body">
                            <div class="form-group">
                                <label>{{ trans('vela::global.parent_page') }}</label>
                                <select class="form-control select2" name="parent_id" id="parent_id">
                                    <option value="">{{ trans('vela::global.none_option') }}</option>
                                    @foreach($pages as $id => $pageTitle)
                                        <option value="{{ $id }}" {{ old('parent_id', $page->parent_id) == $id ? 'selected' : '' }}>{{ $pageTitle }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label>{{ trans('vela::global.meta_title') }}</label>
                                <input class="form-control" type="text" name="meta_title" id="meta_title" value="{{ old('meta_title', $page->meta_title) }}" placeholder="{{ trans('vela::global.meta_title_placeholder') }}">
                            </div>
                            <div class="form-group">
                                <label>{{ trans('vela::global.meta_description') }}</label>
                                <textarea class="form-control" name="meta_description" id="meta_description" rows="3" style="min-height:auto" placeholder="{{ trans('vela::global.meta_description_placeholder') }}">{{ old('meta_description', $page->meta_description) }}</textarea>
                            </div>
                            <div class="form-group">
                                <label>{{ trans('vela::global.social_image') }}</label>
                                <div id="og-image-preview" style="display:none; margin-bottom:8px;">
                                    <img src="" id="og-image-preview-img" style="max-width:100%; border-radius:4px; margin-bottom:6px; display:block;">
                                    <button type="button" class="btn btn-sm btn-outline-danger" id="og-image-remove">
                                        <i class="fas fa-times mr-1"></i> {{ trans('vela::global.remove') }}
                                    </button>
                                </div>
                                <div id="og-image-upload">
                                    <div class="needsclick dropzone" id="og_image-dropzone"></div>
                                    <button type="button" class="btn btn-sm btn-outline-primary btn-block mt-2" id="og-image-browse">
                                        <i class="fas fa-images mr-1"></i> {{ trans('vela::global.browse_media') }}
                                    </button>
                                </div>
                                <input type="hidden" name="og_image_media_id" id="og_image_media_id" value="">
                            </div>
                        </div>
                    </div>

                    {{-- x402 AI Payment (per-page mode only) --}}
                    @if(config('vela.x402.enabled') && config('vela.x402.mode') === 'per_page')
                    <div class="section-card">
                        <div class="section-header"><i class="fas fa-coins"></i> {{ trans('vela::visibility.x402_page_title') }}</div>
                        <div class="section-body">
                            <div class="custom-control custom-switch mb-3">
                                <input type="hidden" name="x402_enabled" value="0">
                                <input type="checkbox" class="custom-control-input" id="x402_enabled" name="x402_enabled" value="1"
                                    {{ old('x402_enabled', $page->x402_enabled) ? 'checked' : '' }}>
                                <label class="custom-control-label" for="x402_enabled">
                                    {{ trans('vela::visibility.x402_page_enable') }}
                                </label>
                            </div>
                            <div id="x402-page-options" style="{{ !old('x402_enabled', $page->x402_enabled) ? 'display:none' : '' }}">
                                <div class="form-group">
                                    <label for="x402_price_usd">{{ trans('vela::visibility.x402_page_price') }}</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend"><span class="input-group-text">$</span></div>
                                        <input type="number" class="form-control" name="x402_price_usd" id="x402_price_usd"
                                            value="{{ old('x402_price_usd', $page->x402_price_usd) }}"
                                            step="0.001" min="0.001" max="1000"
                                            placeholder="{{ config('vela.x402.price_usd', '0.01') }}">
                                    </div>
                                    <small class="form-text text-muted">{{ trans('vela::visibility.x402_page_price_help') }}</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endif

                    {{-- Advanced --}}
                    <div class="section-card">
                        <div class="section-header" style="cursor:pointer;" data-toggle="collapse" data-target="#advanced-settings">
                            <i class="fas fa-cog"></i> {{ trans('vela::global.advanced') }}
                            <i class="fas fa-chevron-down ml-auto" style="font-size:0.7rem;"></i>
                        </div>
                        <div class="collapse" id="advanced-settings">
                            <div class="section-body">
                                <div class="form-group">
                                    <label>{{ trans('vela::global.custom_css') }}</label>
                                    <textarea class="form-control" name="custom_css" id="custom_css" rows="4" style="font-family:monospace; font-size:0.8rem;">{{ old('custom_css', $page->custom_css) }}</textarea>
                                </div>
                                <div class="form-group">
                                    <label>{{ trans('vela::global.custom_js') }}</label>
                                    <textarea class="form-control" name="custom_js" id="custom_js" rows="4" style="font-family:monospace; font-size:0.8rem;">{{ old('custom_js', $page->custom_js) }}</textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@section('scripts')
@parent
<script>
    Dropzone.options.ogImageDropzone = {
    url: '{{ route('vela.admin.pages.storeMedia') }}',
    maxFilesize: 20,
    acceptedFiles: '.jpeg,.jpg,.png,.gif,.webp',
    maxFiles: 1,
    addRemoveLinks: true,
    headers: { 'X-CSRF-TOKEN': "{{ csrf_token() }}" },
    params: { size: 20, width: 4096, height: 4096 },
    success: function (file, response) {
        $('form').find('input[name="og_image"]').remove();
        $('#og_image_media_id').val('');
        $('form').append('<input type="hidden" name="og_image" value="' + response.name + '">');
    },
    removedfile: function (file) {
        file.previewElement.remove();
        if (file.status !== 'error') { $('form').find('input[name="og_image"]').remove(); this.options.maxFiles++; }
    },
    init: function () {
@if($page->og_image)
      var file = {!! json_encode($page->og_image) !!}
      this.options.addedfile.call(this, file)
      this.options.thumbnail.call(this, file, file.preview ?? file.preview_url)
      file.previewElement.classList.add('dz-complete')
      $('form').append('<input type="hidden" name="og_image" value="' + file.file_name + '">')
      this.options.maxFiles = this.options.maxFiles - 1
@endif
    },
    error: function (file, response) {
        var message = $.type(response) === 'string' ? response : response.errors.file;
        file.previewElement.classList.add('dz-error');
        file.previewElement.querySelectorAll('[data-dz-errormessage]').forEach(function(n){ n.textContent = message; });
    }
}
</script>
<script>
$(function() {
    var existingRows = @json($page->rows->load('blocks'));
    if (window.PageEditor) PageEditor.init(existingRows);

    // OG image: browse media library
    $('#og-image-browse').on('click', function() {
        if (window.PageEditor && window.PageEditor.openMediaBrowser) {
            window.PageEditor.openMediaBrowser(function(media) {
                $('form').find('input[name="og_image"]').remove();
                $('#og_image_media_id').val(media.id);
                $('#og-image-preview-img').attr('src', media.url);
                $('#og-image-preview').show();
                $('#og-image-upload').hide();
            });
        }
    });

    // OG image: remove
    $('#og-image-remove').on('click', function() {
        $('form').find('input[name="og_image"]').remove();
        $('#og_image_media_id').val('');
        $('#og-image-preview').hide();
        $('#og-image-upload').show();
        // Reset dropzone if needed
        var dz = Dropzone.forElement('#og_image-dropzone');
        if (dz) { dz.removeAllFiles(true); dz.options.maxFiles = 1; }
    });

    var x402Toggle = document.getElementById('x402_enabled');
    var x402Opts = document.getElementById('x402-page-options');
    if (x402Toggle && x402Opts) {
        x402Toggle.addEventListener('change', function() {
            x402Opts.style.display = this.checked ? '' : 'none';
        });
    }
});
</script>
@endsection
