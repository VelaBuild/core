@extends('vela::layouts.admin')

@section('styles')
@include('vela::admin.partials.editor-styles')
@endsection

@section('content')
<div class="content-editor-page">
    <form method="POST" action="{{ route('vela.admin.pages.store') }}" enctype="multipart/form-data" id="page-form">
        @csrf
        <input type="hidden" name="rows" id="rows-json" value="[]">

        {{-- Page Header --}}
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1" style="font-weight:700; color:#111827;">{{ trans('vela::global.new_page') }}</h4>
                <span class="text-muted" style="font-size:0.85rem;">{{ trans('vela::global.create_page_desc') }}</span>
            </div>
            <a href="{{ route('vela.admin.pages.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left mr-1"></i> {{ trans('vela::global.back') }}
            </a>
        </div>

        <div class="row">
            {{-- Main Content Area --}}
            <div class="col-lg-8">
                {{-- Title & Slug --}}
                <div class="section-card">
                    <div class="section-body">
                        <div class="form-group">
                            <input class="form-control title-input {{ $errors->has('title') ? 'is-invalid' : '' }}" type="text" name="title" id="title" value="{{ old('title', '') }}" required placeholder="{{ trans('vela::global.page_title_placeholder') }}">
                            @if($errors->has('title'))
                                <div class="invalid-feedback">{{ $errors->first('title') }}</div>
                            @endif
                        </div>
                        <div class="form-group">
                            <div class="input-group input-group-sm">
                                <div class="input-group-prepend">
                                    <span class="input-group-text" style="background:#f9fafb; border-color:#e5e7eb; color:#9ca3af; font-size:0.8rem;">slug</span>
                                </div>
                                <input class="form-control form-control-sm {{ $errors->has('slug') ? 'is-invalid' : '' }}" type="text" name="slug" id="slug" value="{{ old('slug', '') }}" required style="border-color:#e5e7eb; font-size:0.85rem;">
                            </div>
                            @if(!\VelaBuild\Core\Models\Page::where('slug', 'home')->exists())
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
                                        <option value="{{ $key }}" {{ old('status', 'draft') === $key ? 'selected' : '' }}>{{ trans('vela::global.status_' . $key) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <input type="hidden" name="locale" value="{{ config('vela.primary_language', 'en') }}">
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
                                        <option value="{{ $id }}" {{ old('parent_id') == $id ? 'selected' : '' }}>{{ $pageTitle }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>

                    {{-- SEO --}}
                    <div class="section-card">
                        <div class="section-header"><i class="fas fa-search"></i> {{ trans('vela::global.seo') }}</div>
                        <div class="section-body">
                            <div class="form-group">
                                <label>{{ trans('vela::global.meta_title') }}</label>
                                <input class="form-control" type="text" name="meta_title" id="meta_title" value="{{ old('meta_title', '') }}" placeholder="{{ trans('vela::global.meta_title_placeholder') }}">
                            </div>
                            <div class="form-group">
                                <label>{{ trans('vela::global.meta_description') }}</label>
                                <textarea class="form-control" name="meta_description" id="meta_description" rows="3" style="min-height:auto" placeholder="{{ trans('vela::global.meta_description_placeholder') }}">{{ old('meta_description', '') }}</textarea>
                            </div>
                            <div class="form-group">
                                <label>{{ trans('vela::global.social_image') }}</label>
                                <div class="needsclick dropzone" id="og_image-dropzone"></div>
                            </div>
                        </div>
                    </div>

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
                                    <textarea class="form-control" name="custom_css" id="custom_css" rows="4" style="font-family:monospace; font-size:0.8rem;">{{ old('custom_css', '') }}</textarea>
                                </div>
                                <div class="form-group">
                                    <label>{{ trans('vela::global.custom_js') }}</label>
                                    <textarea class="form-control" name="custom_js" id="custom_js" rows="4" style="font-family:monospace; font-size:0.8rem;">{{ old('custom_js', '') }}</textarea>
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
    (function() {
        var titleInput = document.getElementById('title');
        var slugInput = document.getElementById('slug');
        var slugManuallyEdited = false;
        slugInput.addEventListener('input', function() { slugManuallyEdited = this.value !== ''; });
        titleInput.addEventListener('input', function() {
            if (!slugManuallyEdited) {
                slugInput.value = this.value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                    .replace(/[^a-z0-9\s-]/g, '').replace(/[\s_]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
            }
        });
    })();
</script>
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
        $('form').find('input[name="og_image"]').remove()
        $('form').append('<input type="hidden" name="og_image" value="' + response.name + '">')
    },
    removedfile: function (file) {
        file.previewElement.remove()
        if (file.status !== 'error') { $('form').find('input[name="og_image"]').remove(); this.options.maxFiles++; }
    },
    init: function () {},
    error: function (file, response) {
        var message = $.type(response) === 'string' ? response : response.errors.file;
        file.previewElement.classList.add('dz-error');
        file.previewElement.querySelectorAll('[data-dz-errormessage]').forEach(function(n){ n.textContent = message; });
    }
}
</script>
<script>
$(function() {
    if (window.PageEditor) PageEditor.init([]);
});
</script>
@endsection
