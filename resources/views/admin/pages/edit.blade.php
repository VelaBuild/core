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
                    {{ trans('vela::global.last_updated') }} <span id="page-updated-at">{{ $page->updated_at->diffForHumans() }}</span>
                    <span title="{{ $page->updated_at->format('jS M Y g:i a') }}"><i class="fas fa-clock" style="font-size:0.75rem;"></i></span>
                </span>
            </div>
            <div class="d-flex" style="gap:8px;">
                {{-- Not public yet: the public URL would 404, so a draft links to
                     the admin preview instead. Saving in place can change the
                     status without a reload, so the link is patched by the save. --}}
                @php $isPublic = in_array($page->status, ['published', 'unlisted']); @endphp
                <a href="{{ \VelaBuild\Core\Http\Controllers\Admin\PageController::viewOrPreviewUrl($page) }}" target="_blank"
                   id="page-view-link" class="btn btn-sm {{ $isPublic ? 'btn-outline-primary' : 'btn-outline-warning' }}">
                    <i class="fas {{ $isPublic ? 'fa-external-link-alt' : 'fa-search' }} mr-1"></i>
                    <span>{{ $isPublic ? trans('vela::global.view') : trans('vela::global.preview') }}</span>
                </a>
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
                                <i class="fas fa-check mr-1" id="page-save-icon"></i> <span id="page-save-label">{{ trans('vela::global.save_page') }}</span>
                            </button>
                            <div class="text-muted text-center mt-2" style="font-size:0.75rem;" id="page-save-hint">
                                <kbd style="font-size:0.7rem;">{{ str_contains(request()->userAgent() ?? '', 'Mac') ? '⌘' : 'Ctrl' }}</kbd>
                                + <kbd style="font-size:0.7rem;">S</kbd>
                            </div>
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

    initInPlaceSave();

    var x402Toggle = document.getElementById('x402_enabled');
    var x402Opts = document.getElementById('x402-page-options');
    if (x402Toggle && x402Opts) {
        x402Toggle.addEventListener('change', function() {
            x402Opts.style.display = this.checked ? '' : 'none';
        });
    }
});

// Saving used to leave for the page list, which is nowhere near where the work
// was: after twenty minutes of arranging blocks, the reward for saving was
// losing your place and clicking back in. The save now stays put, and Ctrl/Cmd+S
// reaches it from anywhere on the page.
function initInPlaceSave() {
    var $form  = $('#page-form');
    var $btn   = $('#page-save-btn');
    var $icon  = $('#page-save-icon');
    var $label = $('#page-save-label');

    var TXT = {
        saving:   @json(trans('vela::global.saving')),
        saved:    @json(trans('vela::global.saved')),
        savePage: @json(trans('vela::global.save_page')),
        failed:   @json(trans('vela::global.save_failed')),
        unsaved:  @json(trans('vela::global.unsaved_changes')),
        justNow:  @json(trans('vela::global.just_now')),
        view:     @json(trans('vela::global.view')),
        preview:  @json(trans('vela::global.preview'))
    };

    var saving = false;
    var savedState = null;

    // The blocks live in the editor's own model rather than in form fields, so
    // "has anything changed" is the two of them together. Comparing against the
    // last saved state beats a dirty flag: typing something and undoing it back
    // is not a change, and an edit made inside the preview frame never reaches
    // a change event out here.
    function currentState() {
        var blocks = (window.PageEditor && PageEditor.collect) ? PageEditor.collect() : '';
        // Work sitting in the open block dialog is not in the blocks yet, so it
        // has to be counted separately or closing the tab mid-edit looks clean.
        // Reads false whenever no dialog is open, which keeps it out of the
        // baseline taken at load.
        var inDialog = (window.PageEditor && PageEditor.blockEditorDirty
            && PageEditor.blockEditorDirty()) ? '\nblock-dialog-dirty' : '';
        return $form.find(':input').not('#rows-json').serialize() + '\n' + blocks + inDialog;
    }

    function setBusy(busy) {
        saving = busy;
        $btn.prop('disabled', busy);
        $icon.attr('class', busy ? 'fas fa-spinner fa-spin mr-1' : 'fas fa-check mr-1');
        $label.text(busy ? TXT.saving : TXT.savePage);
    }

    function toast(message, isError) {
        $('#page-save-toast').remove();
        $('<div id="page-save-toast"></div>')
            .text(message)
            .css({
                position: 'fixed', bottom: '24px', right: '24px', zIndex: 2000,
                padding: '10px 16px', borderRadius: '6px', fontSize: '0.85rem',
                color: '#fff', boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
                background: isError ? '#dc3545' : '#111827'
            })
            .appendTo('body')
            .delay(isError ? 5000 : 2000)
            .fadeOut(200, function() { $(this).remove(); });
    }

    function applyResponse(res) {
        // Rows and blocks that were new went up with a placeholder id. Taking
        // the real ones back is what makes a second save an update rather than
        // a fresh copy of everything.
        if (window.PageEditor && PageEditor.applyIdMap) PageEditor.applyIdMap(res.id_map);

        $('#page-updated-at').text(res.updated_at || TXT.justNow);

        // A picked image has already been copied onto the page; leaving the id
        // in place would have every later save delete and copy it again.
        $form.find('input[name="og_image"]').remove();
        $('#og_image_media_id').val('');
        if (res.og_image) {
            $form.append($('<input type="hidden" name="og_image">').val(res.og_image));
        }

        // Publishing without a reload moves the page between the public URL and
        // the admin preview, and the link would otherwise still point at the
        // one that now 404s.
        $('#page-view-link')
            .attr('href', res.view_url)
            .attr('class', 'btn btn-sm ' + (res.is_public ? 'btn-outline-primary' : 'btn-outline-warning'))
            .find('i').attr('class', 'fas ' + (res.is_public ? 'fa-external-link-alt' : 'fa-search') + ' mr-1')
            .end()
            .find('span').text(res.is_public ? TXT.view : TXT.preview);
    }

    function save() {
        if (saving) return;
        if (!$form[0].checkValidity()) { $form[0].reportValidity(); return; }

        // Never guess at the blocks. The server treats whatever arrives here as
        // the whole page and deletes every row missing from it, so an empty
        // stand-in for "the editor did not answer" is a wipe, not a no-op —
        // which is exactly what a stale published copy of page-editor.js caused.
        if (!window.PageEditor || typeof PageEditor.collect !== 'function') {
            toast(TXT.failed, true);
            return;
        }
        var blocks = PageEditor.collect();
        if ($('#rows-container').children().length > 0 && (!blocks || blocks === '[]')) {
            toast(TXT.failed, true);
            return;
        }
        $('#rows-json').val(blocks);

        // Read before the request, not after: whatever was on screen at the
        // moment it was sent is what got saved, even if typing continues while
        // it is in flight.
        var sentState = currentState();
        setBusy(true);

        $.ajax({
            url: $form.attr('action'),
            method: 'POST', // the form already carries _method=PUT
            data: new FormData($form[0]),
            processData: false,
            contentType: false,
            dataType: 'json',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function(res) {
            applyResponse(res);
            savedState = sentState;
            toast(TXT.saved);
        }).fail(function(xhr) {
            var message = TXT.failed;
            if (xhr.status === 422 && xhr.responseJSON && xhr.responseJSON.errors) {
                var first = Object.keys(xhr.responseJSON.errors)[0];
                message = xhr.responseJSON.errors[first][0];
            }
            toast(message, true);
        }).always(function() {
            setBusy(false);
        });
    }

    // What is on screen in the block editor is not in the page yet — it only
    // reaches the model when that modal's own save runs. Saving the page from
    // under it would quietly write the block as it was before it was opened,
    // so the block is committed first and the page follows once it closes.
    function requestSave() {
        var $modal = $('#block-edit-modal');
        if (!$modal.hasClass('show')) {
            save();
            return;
        }

        // The same modal doubles as the "add block" picker, where there is no
        // block yet and nothing to commit.
        var $commit = $('#save-block-btn');
        if (!$commit.is(':visible')) return;

        $modal.one('hidden.bs.modal hidden.coreui.modal', save);
        $commit.trigger('click');
    }

    $form.on('submit', function(e) {
        e.preventDefault();
        requestSave();
    });

    $(document).on('keydown', function(e) {
        if (!(e.ctrlKey || e.metaKey)) return;
        if ((e.key || '').toLowerCase() !== 's') return;
        e.preventDefault(); // otherwise the browser offers to save the HTML file
        requestSave();
    });

    // Typing inside the preview frame happens in another document, so its
    // keystrokes never reach this one; the editor forwards the shortcut out.
    $(document).on('vela:request-save', requestSave);

    window.addEventListener('beforeunload', function(e) {
        if (saving || savedState === null || currentState() === savedState) return;
        e.preventDefault();
        e.returnValue = TXT.unsaved;
        return TXT.unsaved;
    });

    // Deferred: Dropzone adds a hidden field for an existing social image on its
    // own schedule, and a baseline taken before that lands reads as an unsaved
    // change on a page nobody has touched.
    setTimeout(function() { savedState = currentState(); }, 0);
}
</script>
@endsection
