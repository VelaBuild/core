@extends('vela::layouts.admin')

@section('styles')
@include('vela::admin.partials.editor-styles')
@endsection

@section('content')
<div class="content-editor-page">
    <form method="POST" action="{{ route('vela.admin.contents.update', [$content->id]) }}" enctype="multipart/form-data" id="content-form">
        @method('PUT')
        @csrf

        {{-- Page Header --}}
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1" style="font-weight:700; color:#111827;">{{ trans('vela::global.edit_article') }}</h4>
                <span class="text-muted" style="font-size:0.85rem;">
                    {{ trans('vela::global.last_updated') }} {{ $content->updated_at->diffForHumans() }}
                    <span title="{{ $content->updated_at->format('jS M Y g:i a') }}"><i class="fas fa-clock" style="font-size:0.75rem;"></i></span>
                </span>
            </div>
            <div class="d-flex" style="gap:8px;">
                @if($content->status === 'published')
                    <a href="{{ url('/posts/' . $content->slug) }}" target="_blank" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-external-link-alt mr-1"></i> {{ trans('vela::global.view') }}
                    </a>
                @endif
                <a href="{{ route('vela.admin.contents.index') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left mr-1"></i> {{ trans('vela::global.back') }}
                </a>
            </div>
        </div>

        {{-- Language Tabs --}}
        @php $availableLangs = collect(config('vela.available_languages'))->except(config('vela.primary_language')); @endphp
        @if($availableLangs->count() > 0)
        <ul class="nav lang-tabs mb-4" id="lang-tabs" role="tablist">
            <li class="nav-item">
                @php $primaryLangName = config('vela.available_languages.' . config('vela.primary_language', 'en'), 'English'); @endphp
                <a class="nav-link active" id="tab-primary" data-toggle="tab" href="#pane-primary" role="tab">
                    <img src="{{ asset('flags/1x1/en.svg') }}" alt="{{ $primaryLangName }}" class="flag-icon"> {{ $primaryLangName }}
                </a>
            </li>
            @foreach($availableLangs as $code => $name)
                <li class="nav-item">
                    <a class="nav-link" id="tab-{{ $code }}" data-toggle="tab" href="#pane-{{ $code }}" role="tab">
                        <img src="{{ asset('flags/1x1/' . $code . '.svg') }}" alt="{{ $name }}" class="flag-icon"> {{ $name }}
                    </a>
                </li>
            @endforeach
        </ul>
        @endif

        <div class="tab-content" id="lang-tabs-content">
            {{-- Primary Language --}}
            <div class="tab-pane fade show active" id="pane-primary" role="tabpanel">
                <div class="row">
                    <div class="col-lg-8">
                        {{-- Title --}}
                        <div class="section-card">
                            <div class="section-body">
                                <div class="form-group">
                                    <input class="form-control title-input {{ $errors->has('title') ? 'is-invalid' : '' }}" type="text" name="title" id="title" value="{{ old('title', $content->title) }}" required placeholder="{{ trans('vela::global.article_title_placeholder') }}">
                                    @if($errors->has('title'))
                                        <div class="invalid-feedback">{{ $errors->first('title') }}</div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Content Editor --}}
                        <div class="section-card">
                            <div class="section-header"><i class="fas fa-pen-nib"></i> {{ trans('vela::global.content') }}</div>
                            <div class="section-body">
                                <input type="hidden" name="content" id="content_json" value='{{ old('content', $content->content) }}'>
                                <div id="editorjs"></div>
                            </div>
                        </div>

                        {{-- SEO --}}
                        <div class="section-card">
                            <div class="section-header"><i class="fas fa-search"></i> {{ trans('vela::global.seo') }}</div>
                            <div class="section-body">
                                <div class="form-group">
                                    <label for="description">{{ trans('vela::global.description') }}</label>
                                    <textarea class="form-control" name="description" id="description" rows="3" placeholder="{{ trans('vela::global.brief_description_placeholder') }}">{{ old('description', $content->description) }}</textarea>
                                </div>
                                <div class="form-group">
                                    <label for="keyword">{{ trans('vela::global.keywords') }}</label>
                                    <input type="text" class="form-control" name="keyword" id="keyword" value="{{ old('keyword', $content->keyword) }}" placeholder="{{ trans('vela::global.keyword_placeholder') }}">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="sticky-panel">
                            {{-- Publish --}}
                            <div class="section-card">
                                <div class="section-header"><i class="fas fa-paper-plane"></i> {{ trans('vela::global.publish') }}</div>
                                <div class="section-body">
                                    <div class="form-group">
                                        <label>{{ trans('vela::global.slug') }}</label>
                                        <input class="form-control form-control-sm" type="text" name="slug" id="slug" value="{{ old('slug', $content->slug) }}" style="font-size:0.85rem;">
                                    </div>
                                    <div class="form-group">
                                        <label>{{ trans('vela::global.status') }}</label>
                                        <select class="form-control" name="status" id="status">
                                            @foreach(\VelaBuild\Core\Models\Content::STATUS_SELECT as $key => $label)
                                                <option value="{{ $key }}" {{ old('status', $content->status) === (string) $key ? 'selected' : '' }}>{{ trans('vela::global.status_' . $key) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>{{ trans('vela::global.author') }}</label>
                                        <select class="form-control select2" name="author_id" id="author_id">
                                            @foreach($authors as $id => $entry)
                                                <option value="{{ $id }}" {{ (old('author_id') ? old('author_id') : $content->author->id ?? '') == $id ? 'selected' : '' }}>{{ $entry }}</option>
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
                                    <select class="form-control select2" name="categories[]" id="categories" multiple>
                                        @foreach($categories as $id => $category)
                                            <option value="{{ $id }}" {{ (in_array($id, old('categories', [])) || $content->categories->contains($id)) ? 'selected' : '' }}>{{ $category }}</option>
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

                            {{-- Article Images --}}
                            @if(count($articleImages) > 0)
                            <div class="section-card">
                                <div class="section-header"><i class="fas fa-images"></i> {{ trans('vela::global.article_images') }}</div>
                                <div class="section-body">
                                    <div class="image-grid">
                                        @foreach($articleImages as $image)
                                        <div class="image-grid-item" data-image-id="{{ $image->id }}">
                                            <a href="{{ $image->getUrl() }}" target="_blank">
                                                <img src="{{ $image->getUrl() }}" class="image-thumb" alt="">
                                            </a>
                                            <button type="button" class="remove-btn" onclick="removeContentImage({{ $image->id }})" title="Remove">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            @endif

                            {{-- Unused Images --}}
                            @if(count($otherImages) > 0)
                            <div class="section-card">
                                <div class="section-header"><i class="fas fa-archive"></i> {{ trans('vela::global.unused_images') }}</div>
                                <div class="section-body">
                                    <div class="image-grid">
                                        @foreach($otherImages as $image)
                                        <div class="image-grid-item" data-image-id="{{ $image->id }}">
                                            <a href="{{ $image->getUrl() }}" target="_blank">
                                                <img src="{{ $image->getUrl() }}" class="image-thumb" alt="" style="opacity:0.6;">
                                            </a>
                                            <button type="button" class="remove-btn" onclick="removeContentImage({{ $image->id }})" title="Remove">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Translation Tabs --}}
            @foreach($availableLangs as $code => $name)
                @php
                    $tTitle = $translations[$code]['title'] ?? '';
                    $tDesc = $translations[$code]['description'] ?? '';
                    $tCont = $translations[$code]['content'] ?? '';
                @endphp
                <div class="tab-pane fade" id="pane-{{ $code }}" role="tabpanel">
                    <div class="section-card">
                        <div class="section-header"><i class="fas fa-language"></i> {{ trans('vela::global.translation_label', ['name' => $name]) }}</div>
                        <div class="section-body">
                            <div class="form-group">
                                <label>{{ trans('vela::global.title_lang', ['lang' => strtoupper($code)]) }}</label>
                                <input class="form-control" type="text" name="trans[{{ $code }}][title]" id="trans-{{ $code }}-title" value="{{ $tTitle }}">
                            </div>
                            <div class="form-group">
                                <label>{{ trans('vela::global.description_lang', ['lang' => strtoupper($code)]) }}</label>
                                <textarea class="form-control" name="trans[{{ $code }}][description]" id="trans-{{ $code }}-description" rows="2">{{ $tDesc }}</textarea>
                            </div>
                            <div class="form-group">
                                <label>{{ trans('vela::global.content_lang', ['lang' => strtoupper($code)]) }}</label>
                                <input type="hidden" name="trans[{{ $code }}][content]" id="trans-{{ $code }}-content" value="{{ $tCont }}">
                                <div class="editorjs-trans" data-lang="{{ $code }}"></div>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </form>
</div>
@endsection

@section('scripts')
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
    function removeContentImage(imageId) {
        if (!confirm('{{ trans('vela::global.remove_image_confirm') }}')) return;
        fetch('{{ route("vela.admin.contents.removeContentImage", ":id") }}'.replace(':id', {{ $content->id }}), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Content-Type': 'application/json' },
            body: JSON.stringify({ image_id: imageId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                var el = document.querySelector('[data-image-id="' + imageId + '"]');
                if (el) el.remove();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(() => alert('{{ trans('vela::global.error_removing_image') }}'));
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
                            formData.append('crud_id', {{ $content->id }});
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

        // Lazy-init translation editors on tab show
        var transEditors = {};
        function initTransEditor(lang) {
            if (transEditors[lang]) return;
            var container = document.querySelector('.editorjs-trans[data-lang="' + lang + '"]');
            var hidden = document.getElementById('trans-' + lang + '-content');
            if (!container || !hidden) return;
            if (container.offsetParent === null) { setTimeout(function(){ initTransEditor(lang); }, 200); return; }

            var initData = null;
            try { initData = JSON.parse(hidden.value || 'null'); } catch(e) { initData = null; }

            var ed = new EditorJS({
                holder: container,
                data: initData || { blocks: [] },
                tools: editorTools,
                onChange: function(){ ed.save().then(function(d){ hidden.value = JSON.stringify(d); }); }
            });
            transEditors[lang] = ed;
        }

        document.querySelectorAll('[data-toggle="tab"]').forEach(function(tab) {
            tab.addEventListener('shown.bs.tab', function(e) {
                var lang = e.target.getAttribute('href').replace('#pane-', '');
                if (lang !== 'primary') setTimeout(function(){ initTransEditor(lang); }, 100);
            });
        });

        document.getElementById('content-form').addEventListener('submit', function(e){
            e.preventDefault();
            var promises = [];
            if (editor && editor.save) promises.push(editor.save().then(function(d){ document.getElementById('content_json').value = JSON.stringify(d); }));
            Object.keys(transEditors).forEach(function(lang){
                var ed = transEditors[lang];
                var hidden = document.getElementById('trans-' + lang + '-content');
                if (ed && ed.save) promises.push(ed.save().then(function(d){ hidden.value = JSON.stringify(d); }));
            });

            var btn = document.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> {{ trans('vela::global.saving') }}'; }

            Promise.all(promises).then(function(){
                document.getElementById('content-form').submit();
            }).catch(function(){
                alert('{{ trans('vela::global.error_saving_content') }}');
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-check mr-1"></i> {{ trans('vela::global.save_article') }}'; }
            });
        });
    })();
</script>
@endsection
