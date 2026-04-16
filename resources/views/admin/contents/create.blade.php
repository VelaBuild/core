@extends('vela::layouts.admin')

@section('styles')
@include('vela::admin.partials.editor-styles')
@endsection

@section('content')
<div class="content-editor-page">
    <form method="POST" action="{{ route('vela.admin.contents.store') }}" enctype="multipart/form-data" id="content-form">
        @csrf

        {{-- Page Header --}}
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1" style="font-weight:700; color:#111827;">{{ trans('vela::global.new_article') }}</h4>
                <span class="text-muted" style="font-size:0.85rem;">{{ trans('vela::global.create_article_desc') }}</span>
            </div>
            <div class="d-flex" style="gap:8px;">
                <a href="{{ route('vela.admin.contents.index') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left mr-1"></i> {{ trans('vela::global.back') }}
                </a>
            </div>
        </div>

        <div class="row">
            {{-- Main Content Area --}}
            <div class="col-lg-8">
                {{-- Title --}}
                <div class="section-card">
                    <div class="section-body">
                        <div class="form-group">
                            <input class="form-control title-input {{ $errors->has('title') ? 'is-invalid' : '' }}" type="text" name="title" id="title" value="{{ old('title', '') }}" required placeholder="{{ trans('vela::global.article_title_placeholder') }}">
                            @if($errors->has('title'))
                                <div class="invalid-feedback">{{ $errors->first('title') }}</div>
                            @endif
                        </div>
                        <div class="form-group">
                            <div class="input-group input-group-sm">
                                <div class="input-group-prepend">
                                    <span class="input-group-text" style="background:#f9fafb; border-color:#e5e7eb; color:#9ca3af; font-size:0.8rem;">slug</span>
                                </div>
                                <input class="form-control form-control-sm {{ $errors->has('slug') ? 'is-invalid' : '' }}" type="text" name="slug" id="slug" value="{{ old('slug', '') }}" style="border-color:#e5e7eb; font-size:0.85rem;">
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Content Editor --}}
                <div class="section-card">
                    <div class="section-header"><i class="fas fa-pen-nib"></i> {{ trans('vela::global.content') }}</div>
                    <div class="section-body">
                        <input type="hidden" name="content" id="content_json" value='{{ old('content', '') }}'>
                        <div id="editorjs"></div>
                    </div>
                </div>

                {{-- SEO --}}
                <div class="section-card">
                    <div class="section-header"><i class="fas fa-search"></i> {{ trans('vela::global.seo') }}</div>
                    <div class="section-body">
                        <div class="form-group">
                            <label for="description">{{ trans('vela::cruds.content.fields.description') }}</label>
                            <textarea class="form-control {{ $errors->has('description') ? 'is-invalid' : '' }}" name="description" id="description" rows="3" placeholder="{{ trans('vela::global.brief_description_placeholder') }}">{{ old('description') }}</textarea>
                            <span class="help-block">{{ trans('vela::global.displayed_in_search') }}</span>
                        </div>
                        <div class="form-group">
                            <label for="keyword">{{ trans('vela::cruds.content.fields.keyword') }}</label>
                            <input type="text" class="form-control {{ $errors->has('keyword') ? 'is-invalid' : '' }}" name="keyword" id="keyword" value="{{ old('keyword') }}" placeholder="{{ trans('vela::global.keyword_placeholder') }}">
                            <span class="help-block">{{ trans('vela::global.comma_separated_keywords') }}</span>
                        </div>
                    </div>
                </div>

                {{-- Translations --}}
                @php $availableLangs = collect(config('vela.available_languages'))->except(config('vela.primary_language')); @endphp
                @if($availableLangs->count() > 0)
                <div class="section-card">
                    <div class="section-header"><i class="fas fa-language"></i> {{ trans('vela::global.translations') }}</div>
                    <div class="section-body">
                        <ul class="nav nav-tabs mb-3" id="lang-tabs" role="tablist">
                            @foreach($availableLangs as $code => $name)
                                <li class="nav-item">
                                    <a class="nav-link {{ $loop->first ? 'active' : '' }}" data-toggle="tab" href="#pane-{{ $code }}" role="tab">{{ strtoupper($code) }}</a>
                                </li>
                            @endforeach
                        </ul>
                        <div class="tab-content" id="lang-tabs-content">
                            @foreach($availableLangs as $code => $name)
                                <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="pane-{{ $code }}" role="tabpanel">
                                    <div class="form-group">
                                        <label>{{ trans('vela::global.title_lang', ['lang' => strtoupper($code)]) }}</label>
                                        <input class="form-control" type="text" name="trans[{{ $code }}][title]" value="">
                                    </div>
                                    <div class="form-group">
                                        <label>{{ trans('vela::global.slug_lang', ['lang' => strtoupper($code)]) }}</label>
                                        <input class="form-control" type="text" name="trans[{{ $code }}][slug]" value="">
                                    </div>
                                    <div class="form-group">
                                        <label>{{ trans('vela::global.description_lang', ['lang' => strtoupper($code)]) }}</label>
                                        <textarea class="form-control" name="trans[{{ $code }}][description]" rows="2"></textarea>
                                    </div>
                                    <div class="form-group">
                                        <label>{{ trans('vela::global.content_lang', ['lang' => strtoupper($code)]) }}</label>
                                        <input type="hidden" name="trans[{{ $code }}][content]" id="trans-{{ $code }}-content" value="">
                                        <div class="editorjs-trans" data-lang="{{ $code }}"></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif
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
                                <select class="form-control {{ $errors->has('status') ? 'is-invalid' : '' }}" name="status" id="status">
                                    @foreach(\VelaBuild\Core\Models\Content::STATUS_SELECT as $key => $label)
                                        <option value="{{ $key }}" {{ old('status', 'draft') === (string) $key ? 'selected' : '' }}>{{ trans('vela::global.status_' . $key) }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label>{{ trans('vela::global.author') }}</label>
                                <select class="form-control select2 {{ $errors->has('author') ? 'is-invalid' : '' }}" name="author_id" id="author_id">
                                    @foreach($authors as $id => $entry)
                                        <option value="{{ $id }}" {{ old('author_id', auth('vela')->id()) == $id ? 'selected' : '' }}>{{ $entry }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button class="btn publish-btn btn-block" type="submit">
                                <i class="fas fa-check mr-1"></i> {{ trans('vela::global.save_article') }}
                            </button>
                        </div>
                    </div>

                    {{-- Categories --}}
                    <div class="section-card">
                        <div class="section-header"><i class="fas fa-tags"></i> {{ trans('vela::global.categories') }}</div>
                        <div class="section-body">
                            <select class="form-control select2 {{ $errors->has('categories') ? 'is-invalid' : '' }}" name="categories[]" id="categories" multiple>
                                @foreach($categories as $id => $category)
                                    <option value="{{ $id }}" {{ in_array($id, old('categories', [])) ? 'selected' : '' }}>{{ $category }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Featured Image --}}
                    <div class="section-card">
                        <div class="section-header"><i class="fas fa-image"></i> {{ trans('vela::global.featured_image') }}</div>
                        <div class="section-body">
                            <div class="needsclick dropzone" id="main_image-dropzone"></div>
                        </div>
                    </div>

                    {{-- Gallery --}}
                    <div class="section-card">
                        <div class="section-header"><i class="fas fa-images"></i> {{ trans('vela::global.gallery') }}</div>
                        <div class="section-body">
                            <div class="needsclick dropzone" id="gallery-dropzone"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
    // Auto-generate slug from title
    (function() {
        var titleInput = document.getElementById('title');
        var slugInput = document.getElementById('slug');
        var slugManuallyEdited = false;

        slugInput.addEventListener('input', function() {
            slugManuallyEdited = this.value !== '';
        });

        titleInput.addEventListener('input', function() {
            if (!slugManuallyEdited) {
                slugInput.value = this.value
                    .toLowerCase()
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                    .replace(/[^a-z0-9\s-]/g, '')
                    .replace(/[\s_]+/g, '-')
                    .replace(/-+/g, '-')
                    .replace(/^-|-$/g, '');
            }
        });
    })();
</script>
<script>
    Dropzone.options.mainImageDropzone = {
    url: '{{ route('vela.admin.contents.storeMedia') }}',
    maxFilesize: 20,
    acceptedFiles: '.jpeg,.jpg,.png,.gif,.webp',
    maxFiles: 1,
    addRemoveLinks: true,
    headers: { 'X-CSRF-TOKEN': "{{ csrf_token() }}" },
    params: { size: 20, width: 4096, height: 4096 },
    success: function (file, response) {
      $('form').find('input[name="main_image"]').remove()
      $('form').append('<input type="hidden" name="main_image" value="' + response.name + '">')
    },
    removedfile: function (file) {
      file.previewElement.remove()
      if (file.status !== 'error') {
        $('form').find('input[name="main_image"]').remove()
        this.options.maxFiles = this.options.maxFiles + 1
      }
    },
    init: function () {
@if(isset($content) && $content->main_image)
      var file = {!! json_encode($content->main_image) !!}
          this.options.addedfile.call(this, file)
      this.options.thumbnail.call(this, file, file.preview ?? file.preview_url)
      file.previewElement.classList.add('dz-complete')
      $('form').append('<input type="hidden" name="main_image" value="' + file.file_name + '">')
      this.options.maxFiles = this.options.maxFiles - 1
@endif
    },
    error: function (file, response) {
        var message = $.type(response) === 'string' ? response : response.errors.file;
        file.previewElement.classList.add('dz-error')
        file.previewElement.querySelectorAll('[data-dz-errormessage]').forEach(function(node) { node.textContent = message; });
    }
}
</script>
<script>
    var uploadedGalleryMap = {}
Dropzone.options.galleryDropzone = {
    url: '{{ route('vela.admin.contents.storeMedia') }}',
    maxFilesize: 20,
    acceptedFiles: '.jpeg,.jpg,.png,.gif,.webp',
    addRemoveLinks: true,
    headers: { 'X-CSRF-TOKEN': "{{ csrf_token() }}" },
    params: { size: 20, width: 4096, height: 4096 },
    success: function (file, response) {
      $('form').append('<input type="hidden" name="gallery[]" value="' + response.name + '">')
      uploadedGalleryMap[file.name] = response.name
    },
    removedfile: function (file) {
      file.previewElement.remove()
      var name = typeof file.file_name !== 'undefined' ? file.file_name : uploadedGalleryMap[file.name]
      $('form').find('input[name="gallery[]"][value="' + name + '"]').remove()
    },
    init: function () {
@if(isset($content) && $content->gallery)
      var files = {!! json_encode($content->gallery) !!}
          for (var i in files) {
          var file = files[i]
          this.options.addedfile.call(this, file)
          this.options.thumbnail.call(this, file, file.preview ?? file.preview_url)
          file.previewElement.classList.add('dz-complete')
          $('form').append('<input type="hidden" name="gallery[]" value="' + file.file_name + '">')
        }
@endif
    },
    error: function (file, response) {
        var message = $.type(response) === 'string' ? response : response.errors.file;
        file.previewElement.classList.add('dz-error')
        file.previewElement.querySelectorAll('[data-dz-errormessage]').forEach(function(node) { node.textContent = message; });
    }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/editorjs@2"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/header@2"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/list@1"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/quote@2"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/table@2"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/embed@2"></script>
<script src="https://cdn.jsdelivr.net/npm/@editorjs/image@2"></script>
<script>
    (function(){
        var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var baseData = null;
        try { baseData = JSON.parse(document.getElementById('content_json').value || 'null'); } catch(e) { baseData = null; }

        var editorTools = {
            header: Header, list: List, quote: Quote, table: Table, embed: Embed,
            image: {
                class: ImageTool,
                config: {
                    uploader: {
                        uploadByFile(file){
                            var formData = new FormData();
                            formData.append('upload', file);
                            formData.append('crud_id', 0);
                            return fetch("{{ route('vela.admin.contents.storeCKEditorImages') }}", {
                                method: 'POST', headers: { 'X-CSRF-TOKEN': csrf }, body: formData
                            }).then(r=>r.json()).then(function(resp){
                                return resp && resp.url ? { success: 1, file: { url: resp.url } } : Promise.reject('Upload failed');
                            });
                        }
                    }
                }
            }
        };

        var editor = new EditorJS({
            holder: 'editorjs',
            data: baseData || { blocks: [] },
            tools: editorTools,
            onChange: function(){ editor.save().then(function(d){ document.getElementById('content_json').value = JSON.stringify(d); }); }
        });

        var transEditors = {};
        document.querySelectorAll('.editorjs-trans').forEach(function(container){
            var lang = container.getAttribute('data-lang');
            var hidden = document.getElementById('trans-' + lang + '-content');
            var initData = null;
            try { initData = JSON.parse(hidden.value || 'null'); } catch(e) { initData = null; }
            var ed = new EditorJS({
                holder: container,
                data: initData || { blocks: [] },
                tools: editorTools,
                onChange: function(){ ed.save().then(function(d){ hidden.value = JSON.stringify(d); }); }
            });
            transEditors[lang] = ed;
        });

        document.getElementById('content-form').addEventListener('submit', function(){
            if(editor && editor.save) editor.save().then(function(d){ document.getElementById('content_json').value = JSON.stringify(d); });
            Object.keys(transEditors).forEach(function(lang){
                var ed = transEditors[lang];
                var hidden = document.getElementById('trans-' + lang + '-content');
                if(ed && ed.save) ed.save().then(function(d){ hidden.value = JSON.stringify(d); });
            });
        });
    })();
</script>
@endsection
