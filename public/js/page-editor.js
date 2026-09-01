// --- PageEditor Plugin Registry ---
// Must be set up before the IIFE so third-party code can call registerBlockType
// before the editor initializes.
window.PageEditor = window.PageEditor || {};
PageEditor.blockTypes = {};

PageEditor.registerBlockType = function(name, config) {
    // config: { icon, label, defaults, renderPreview(block), renderEditor(block), initEditor(block), collectData(block) }
    PageEditor.blockTypes[name] = config;
};

(function($) {
    'use strict';

    // --- State ---
    var rows = [];
    var editingRowId = null;
    var editingColIndex = null;
    var editingBlockIndex = null;
    var editorJsInstance = null;
    var _idCounter = 1;
    var _slugManuallyEdited = false;
    // Work done in the block dialog reaches the page only when that dialog's
    // own save runs, so until then closing it discards everything typed.
    var _blockEditTouched = false;
    var _committingBlock = false;

    // --- Undo ---------------------------------------------------------------
    //
    // Everything the editor does was one way. A block dragged into the wrong
    // column, a section deleted, wording typed over — the only way back was to
    // do the reverse by hand, and for typing there was no reverse.
    //
    // Two stacks, because there are two things a person can be looking at: the
    // page and its blocks, or one imported section inside the dialog. Ctrl+Z
    // means "undo what I am looking at", so which stack answers depends on
    // whether the dialog is open.
    //
    // States are whole serialised snapshots. The editor's model is small (a
    // page of blocks, or one section's markup) and snapshots cannot drift out
    // of step with the model the way a list of inverse operations can.
    var HISTORY_LIMIT = 60;
    var HISTORY_MERGE_MS = 800;
    var _restoring = false;

    function newHistory() {
        return { past: [], future: [], last: null, at: 0 };
    }
    var _pageHistory = newHistory();
    var _blockHistory = newHistory();

    /**
     * Record that something changed, keeping the state as it was before.
     *
     * Typing arrives as a change every couple of hundred milliseconds. Each one
     * as its own step would mean tapping Ctrl+Z once per letter, so changes
     * that follow closely on the last are folded into it: what is kept is the
     * state from before the burst started, which is where Ctrl+Z should land.
     */
    function noteHistory(store, state) {
        if (_restoring || state === null) return;

        // The opening state is timestamped as long ago on purpose: it is not an
        // edit, so the first real change must never be folded into it. Dated to
        // now instead, a section changed within a moment of being opened had
        // nothing to go back to.
        if (store.last === null) { store.last = state; store.at = 0; return; }
        if (state === store.last) return;

        var now = Date.now();
        if (now - store.at >= HISTORY_MERGE_MS) {
            store.past.push(store.last);
            if (store.past.length > HISTORY_LIMIT) store.past.shift();
        }
        // Any fresh change makes whatever was undone unreachable again.
        store.future.length = 0;
        store.last = state;
        store.at = now;
    }

    function stepHistory(store, from, to, current, apply) {
        // A change still inside the merge window has not been pushed yet, and
        // without this the first Ctrl+Z would skip over it to the step before.
        if (store.last !== null && current !== store.last) {
            from.push(store.last);
            store.last = current;
        }
        if (!from.length) return false;

        to.push(current);
        var state = from.pop();
        store.last = state;
        store.at = 0;   // the next change starts a new step rather than merging
        apply(state);
        return true;
    }

    function undoHistory(store, current, apply) {
        return stepHistory(store, store.past, store.future, current, apply);
    }

    function redoHistory(store, current, apply) {
        return stepHistory(store, store.future, store.past, current, apply);
    }

    function uid() { return 'new_' + (_idCounter++); }

    // --- Config (set via window.PageEditorConfig from the view) ---
    function getUploadUrl() {
        return (window.PageEditorConfig && window.PageEditorConfig.uploadUrl)
            ? window.PageEditorConfig.uploadUrl
            : '/admin/pages/ckmedia';
    }

    function getMediaUrl() {
        return (window.PageEditorConfig && window.PageEditorConfig.mediaUrl)
            ? window.PageEditorConfig.mediaUrl
            : '/admin/media';
    }

    function getMediaUploadUrl() {
        return (window.PageEditorConfig && window.PageEditorConfig.mediaUploadUrl)
            ? window.PageEditorConfig.mediaUploadUrl
            : '/admin/media/media';
    }

    // --- Helper ---
    // Image URL field with a media-library picker, live thumbnail and a clear button.
    function imageField(id, label, value) {
        value = value || '';
        return '<div class="form-group"><label>' + label + '</label>' +
            '<div class="input-group">' +
            '<input type="text" class="form-control media-field-input" id="' + id + '" value="' + escHtml(value) + '" placeholder="Pick from the media library or paste a URL">' +
            '<div class="input-group-append">' +
            '<button type="button" class="btn btn-outline-primary browse-media-field" title="Media Library"><i class="fas fa-images mr-1"></i> Choose Image</button>' +
            '<button type="button" class="btn btn-outline-secondary clear-media-field" title="Remove image"><i class="fas fa-times"></i></button>' +
            '</div></div>' +
            '<div class="media-field-preview mt-2"' + (value ? '' : ' style="display:none;"') + '>' +
            '<img src="' + (value ? escHtml(value) : '') + '" style="max-height:80px;border-radius:4px;border:1px solid #e5e7eb;">' +
            '</div></div>';
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // What a <input type="color"> can actually be set to.
    //
    // These fields are paired: a swatch and a text box, and the text box is
    // what gets saved. A stored value the swatch cannot represent — the
    // gradient an AI build writes, a named colour, an rgba() — was being put
    // into it anyway. The browser refuses it, logs "does not conform to the
    // required format", and shows black; the value looks lost, and one nudge
    // of the swatch really does overwrite it. The text box still carries the
    // real value, so the swatch just needs a colour it can show.
    function swatchValue(value, fallback) {
        return /^#[0-9a-fA-F]{6}$/.test(String(value || '')) ? value : fallback;
    }

    // --- Internal helpers used by built-in block types ---

    function initEditorJS(block) {
        if (editorJsInstance) { try { editorJsInstance.destroy(); } catch(e) {} editorJsInstance = null; }
        var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var uploadUrl = getUploadUrl();
        var data = block.content && block.content.blocks ? block.content : { blocks: [] };
        editorJsInstance = new EditorJS({
            holder: 'block-editorjs',
            data: data,
            // Rich text lives inside the editor instance rather than in a form
            // field, so this is the only place typing here announces itself.
            onChange: function() { _blockEditTouched = true; },
            tools: {
                header: Header,
                image: {
                    class: ImageTool,
                    config: {
                        uploader: {
                            uploadByFile: function(file) {
                                var formData = new FormData();
                                formData.append('upload', file);
                                formData.append('crud_id', 0);
                                return fetch(uploadUrl, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrf },
                                    body: formData
                                }).then(function(r) { return r.json(); }).then(function(resp) {
                                    if (resp && resp.url) return { success: 1, file: { url: resp.url } };
                                    return Promise.reject('Upload failed');
                                });
                            },
                            uploadByUrl: function(url) {
                                return Promise.resolve({ success: 1, file: { url: url } });
                            }
                        }
                    }
                },
                list: List,
                checklist: Checklist,
                quote: Quote,
                warning: Warning,
                delimiter: Delimiter,
                table: Table,
                embed: Embed,
                inlineCode: InlineCode,
                marker: Marker,
                underline: Underline
            }
        });
    }

    function initBlockImageDropzone() {
        var el = document.getElementById('block-image-dropzone');
        if (!el) return;
        var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var uploadUrl = getUploadUrl();
        new Dropzone(el, {
            url: uploadUrl,
            maxFilesize: 20,
            acceptedFiles: '.jpeg,.jpg,.png,.gif,.webp',
            maxFiles: 1,
            addRemoveLinks: true,
            headers: { 'X-CSRF-TOKEN': csrf },
            paramName: 'upload',
            params: { crud_id: 0 },
            success: function(file, response) {
                if (response && response.url) {
                    $('#img-url').val(response.url);
                }
            },
            removedfile: function(file) {
                file.previewElement.remove();
                this.options.maxFiles = this.options.maxFiles + 1;
            },
            error: function(file, response) {
                var message = (typeof response === 'string') ? response : (response.errors ? response.errors.file : 'Upload failed');
                file.previewElement.classList.add('dz-error');
                var refs = file.previewElement.querySelectorAll('[data-dz-errormessage]');
                for (var i = 0; i < refs.length; i++) { refs[i].textContent = message; }
            }
        });
    }

    // --- Media Browser ---
    var _mediaBrowserCallback = null;
    var _mediaBrowserCursor = null;
    var _mediaBrowserLoading = false;
    var _mediaBrowserDone = false;

    var _mediaBrowserMulti = false;
    var _mediaBrowserMultiCallback = null;
    var _mediaBrowserSelected = [];

    function openMediaBrowser(callback) {
        // Every consumer of this writes the pick straight in with .val(), which
        // raises no input event, so a chosen image would not register as work
        // done and the dialog would close over it without a word.
        _mediaBrowserCallback = function(media) {
            _blockEditTouched = true;
            callback(media);
        };
        _mediaBrowserMulti = false;
        _mediaBrowserMultiCallback = null;
        _mediaBrowserSelected = [];
        _mediaBrowserCursor = null;
        _mediaBrowserDone = false;
        _mediaBrowserLoading = false;
        $('#media-browser-grid').empty().removeClass('multi-select');
        $('#media-browser-empty').hide();
        $('#media-browser-bulk-bar').remove();
        loadMediaBrowserPage();
        $('#media-browser-modal').modal('show');
    }

    function openMediaBrowserMulti(callback) {
        _mediaBrowserCallback = null;
        _mediaBrowserMulti = true;
        _mediaBrowserMultiCallback = callback;
        _mediaBrowserSelected = [];
        _mediaBrowserCursor = null;
        _mediaBrowserDone = false;
        _mediaBrowserLoading = false;
        $('#media-browser-grid').empty().addClass('multi-select');
        $('#media-browser-empty').hide();
        $('#media-browser-bulk-bar').remove();
        var $bar = $('<div id="media-browser-bulk-bar" style="padding:10px 15px;background:#f0f9ff;border-bottom:1px solid #bae6fd;display:flex;justify-content:space-between;align-items:center;">' +
            '<span style="color:#0369a1;font-size:0.9em;"><i class="fas fa-info-circle mr-1"></i> Click images to select, then add all at once.</span>' +
            '<button type="button" class="btn btn-sm btn-primary" id="media-browser-add-selected" disabled><i class="fas fa-plus mr-1"></i> Add <span id="bulk-count">0</span> Selected</button>' +
            '</div>');
        $('#media-browser-modal .modal-body').prepend($bar);
        loadMediaBrowserPage();
        $('#media-browser-modal').modal('show');
    }

    function loadMediaBrowserPage() {
        if (_mediaBrowserLoading || _mediaBrowserDone) return;
        _mediaBrowserLoading = true;
        $('#media-browser-loading').show();
        var url = getMediaUrl() + '?per_page=36';
        if (_mediaBrowserCursor) url += '&cursor=' + _mediaBrowserCursor;
        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.data.length === 0 && !_mediaBrowserCursor) {
                    $('#media-browser-empty').show();
                }
                data.data.forEach(function(item) {
                    var thumb = item.preview || item.thumb || item.url;
                    var alt = (item.custom_properties && item.custom_properties.alt_text) || '';
                    var $el = $('<div class="media-browser-item">')
                        .attr('data-id', item.id)
                        .attr('data-url', item.url)
                        .attr('data-alt', alt)
                        .append($('<img>').attr('src', thumb).attr('alt', item.file_name).attr('loading', 'lazy'))
                        .append($('<div class="media-browser-name">').text(item.file_name));
                    $('#media-browser-grid').append($el);
                });
                _mediaBrowserCursor = data.next_cursor;
                if (!data.next_cursor) _mediaBrowserDone = true;
                _mediaBrowserLoading = false;
                $('#media-browser-loading').hide();
            });
    }

    window.PageEditor.openMediaBrowser = openMediaBrowser;

    function getVideoPreviewHtml(url) {
        var embedUrl = '';
        var ytMatch = url.match(/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
        var viMatch = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
        if (ytMatch) embedUrl = 'https://www.youtube.com/embed/' + ytMatch[1];
        else if (viMatch) embedUrl = 'https://player.vimeo.com/video/' + viMatch[1];
        if (!embedUrl) return '';
        return '<div style="position:relative;padding-bottom:56.25%;height:0;"><iframe src="' + embedUrl + '" style="position:absolute;top:0;left:0;width:100%;height:100%;border:none;" allowfullscreen></iframe></div>';
    }

    function buildAccordionItemRow(item, i) {
        return '<div class="accordion-item-row">' +
            '<div style="flex:1;">' +
                '<input type="text" class="form-control form-control-sm mb-1 acc-title" placeholder="Question / Title" value="' + escHtml(item.title || '') + '">' +
                '<textarea class="form-control form-control-sm acc-body" rows="2" placeholder="Answer / Body">' + escHtml(item.body || '') + '</textarea>' +
            '</div>' +
            '<button type="button" class="btn btn-xs btn-danger remove-accordion-item" data-index="' + i + '"><i class="fas fa-trash"></i></button>' +
        '</div>';
    }

    function initAccordionEditor(block) {
        $(document).off('click', '#add-accordion-item').on('click', '#add-accordion-item', function() {
            var $list = $('#accordion-items-list');
            var count = $list.find('.accordion-item-row').length;
            $list.append(buildAccordionItemRow({ title: '', body: '' }, count));
        });
        $(document).off('click', '.remove-accordion-item').on('click', '.remove-accordion-item', function() {
            $(this).closest('.accordion-item-row').remove();
        });
    }

    function buildCarouselSlideRow(slide, i) {
        var thumb = slide.image_url ? '<img src="' + escHtml(slide.image_url) + '" style="width:100px;height:60px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">' : '<div style="width:100px;height:60px;background:#f3f4f6;border-radius:6px;border:2px dashed #d1d5db;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:0.7em;">No image</div>';
        return '<div class="carousel-slide-row" style="display:flex;gap:10px;align-items:flex-start;margin-bottom:8px;padding:8px;background:#f8f9fa;border-radius:6px;">' +
            '<div class="slide-thumb" style="flex-shrink:0;cursor:pointer;" title="Click to change">' + thumb + '</div>' +
            '<input type="hidden" class="slide-image" value="' + escHtml(slide.image_url || '') + '">' +
            '<div style="flex:1;min-width:0;">' +
                '<input type="text" class="form-control form-control-sm mb-1 slide-caption" placeholder="Caption (optional)" value="' + escHtml(slide.caption || '') + '">' +
                '<input type="text" class="form-control form-control-sm slide-link" placeholder="Link URL (optional)" value="' + escHtml(slide.link || '') + '">' +
            '</div>' +
            '<button type="button" class="btn btn-sm btn-outline-danger remove-carousel-slide" title="Remove"><i class="fas fa-times"></i></button>' +
        '</div>';
    }

    function initCarouselEditor(block) {
        $(document).off('click', '#add-carousel-slide').on('click', '#add-carousel-slide', function() {
            openMediaBrowser(function(media) {
                var $list = $('#carousel-slides-list');
                $list.append(buildCarouselSlideRow({ image_url: media.url, caption: media.alt || '' }, $list.find('.carousel-slide-row').length));
            });
        });
        $(document).off('click', '#bulk-add-carousel').on('click', '#bulk-add-carousel', function() {
            openMediaBrowserMulti(function(items) {
                var $list = $('#carousel-slides-list');
                items.forEach(function(media) {
                    $list.append(buildCarouselSlideRow({ image_url: media.url, caption: media.alt || '' }, $list.find('.carousel-slide-row').length));
                });
            });
        });
        $(document).off('click', '.slide-thumb').on('click', '.slide-thumb', function() {
            var $row = $(this).closest('.carousel-slide-row');
            openMediaBrowser(function(media) {
                $row.find('.slide-image').val(media.url);
                $row.find('.slide-thumb').html('<img src="' + escHtml(media.url) + '" style="width:100px;height:60px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">');
                if (media.alt && !$row.find('.slide-caption').val()) $row.find('.slide-caption').val(media.alt);
            });
        });
        $(document).off('click', '.remove-carousel-slide').on('click', '.remove-carousel-slide', function() {
            $(this).closest('.carousel-slide-row').remove();
        });
    }

    function buildGalleryImageRow(img, i) {
        var thumb = img.url ? '<img src="' + escHtml(img.url) + '" style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">' : '<div style="width:80px;height:80px;background:#f3f4f6;border-radius:6px;border:2px dashed #d1d5db;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:0.75em;">No image</div>';
        return '<div class="gallery-image-row" style="display:flex;gap:10px;align-items:flex-start;margin-bottom:8px;padding:8px;background:#f8f9fa;border-radius:6px;">' +
            '<div class="gal-thumb" style="flex-shrink:0;cursor:pointer;" title="Click to change">' + thumb + '</div>' +
            '<input type="hidden" class="gal-url" value="' + escHtml(img.url || '') + '">' +
            '<div style="flex:1;min-width:0;">' +
                '<input type="text" class="form-control form-control-sm gal-alt" placeholder="Caption / alt text" value="' + escHtml(img.alt || img.caption || '') + '">' +
            '</div>' +
            '<button type="button" class="btn btn-sm btn-outline-danger remove-gallery-image" title="Remove"><i class="fas fa-times"></i></button>' +
        '</div>';
    }

    function initGalleryEditor(block) {
        $(document).off('click', '#add-gallery-image').on('click', '#add-gallery-image', function() {
            openMediaBrowser(function(media) {
                var $list = $('#gallery-images-list');
                $list.append(buildGalleryImageRow({ url: media.url, alt: media.alt || '' }, $list.find('.gallery-image-row').length));
            });
        });
        $(document).off('click', '#bulk-add-gallery').on('click', '#bulk-add-gallery', function() {
            openMediaBrowserMulti(function(items) {
                var $list = $('#gallery-images-list');
                items.forEach(function(media) {
                    $list.append(buildGalleryImageRow({ url: media.url, alt: media.alt || '' }, $list.find('.gallery-image-row').length));
                });
            });
        });
        $(document).off('click', '.gal-thumb').on('click', '.gal-thumb', function() {
            var $row = $(this).closest('.gallery-image-row');
            openMediaBrowser(function(media) {
                $row.find('.gal-url').val(media.url);
                $row.find('.gal-thumb').html('<img src="' + escHtml(media.url) + '" style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">');
                if (media.alt && !$row.find('.gal-alt').val()) $row.find('.gal-alt').val(media.alt);
            });
        });
        $(document).off('click', '.remove-gallery-image').on('click', '.remove-gallery-image', function() {
            $(this).closest('.gallery-image-row').remove();
        });
    }

    function buildTestimonialRow(t, i) {
        var photoThumb = t.photo_url ? '<img src="' + escHtml(t.photo_url) + '" style="width:48px;height:48px;object-fit:cover;border-radius:50%;border:1px solid #e5e7eb;">' : '<div style="width:48px;height:48px;background:#f3f4f6;border-radius:50%;border:2px dashed #d1d5db;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:0.65em;">Photo</div>';
        return '<div class="testi-row" style="margin-bottom:10px;padding:10px;background:#f8f9fa;border-radius:6px;border-left:3px solid #3b82f6;">' +
            '<div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:8px;">' +
                '<div class="testi-photo-thumb" style="flex-shrink:0;cursor:pointer;" title="Click to set photo">' + photoThumb + '</div>' +
                '<input type="hidden" class="testi-photo" value="' + escHtml(t.photo_url || '') + '">' +
                '<div style="flex:1;">' +
                    '<input type="text" class="form-control form-control-sm mb-1 testi-name" placeholder="Name" value="' + escHtml(t.name || '') + '">' +
                    '<input type="text" class="form-control form-control-sm testi-title" placeholder="Title / Role" value="' + escHtml(t.title || '') + '">' +
                '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-danger remove-testimonial" title="Remove"><i class="fas fa-times"></i></button>' +
            '</div>' +
            '<textarea class="form-control form-control-sm testi-quote" rows="2" placeholder="Quote">' + escHtml(t.quote || '') + '</textarea>' +
        '</div>';
    }

    function initTestimonialsEditor(block) {
        $(document).off('click', '#add-testimonial').on('click', '#add-testimonial', function() {
            var $list = $('#testimonials-list');
            var count = $list.find('.testi-row').length;
            $list.append(buildTestimonialRow({ quote: '', name: '', title: '', photo_url: '' }, count));
        });
        $(document).off('click', '.testi-photo-thumb').on('click', '.testi-photo-thumb', function() {
            var $row = $(this).closest('.testi-row');
            openMediaBrowser(function(media) {
                $row.find('.testi-photo').val(media.url);
                $row.find('.testi-photo-thumb').html('<img src="' + escHtml(media.url) + '" style="width:48px;height:48px;object-fit:cover;border-radius:50%;border:1px solid #e5e7eb;">');
            });
        });
        $(document).off('click', '.remove-testimonial').on('click', '.remove-testimonial', function() {
            $(this).closest('.testi-row').remove();
        });
    }

    function buildIconBoxRow(item, i) {
        var icon = item.icon || 'fas fa-star';
        return '<div class="ib-row" style="margin-bottom:10px;padding:10px;background:#f8f9fa;border-radius:4px;border-left:3px solid #3b82f6;">' +
            '<div class="form-row align-items-center mb-2">' +
                '<div class="col-auto">' +
                    '<div class="ib-icon-preview" style="width:40px;height:40px;background:#e8f0fe;border-radius:6px;display:flex;align-items:center;justify-content:center;">' +
                        '<i class="' + escHtml(icon) + '" style="font-size:1.2em;color:#3b82f6;"></i>' +
                    '</div>' +
                '</div>' +
                '<div class="col">' +
                    '<input type="text" class="form-control form-control-sm ib-icon" placeholder="Icon class (e.g. fas fa-star)" value="' + escHtml(icon) + '">' +
                '</div>' +
            '</div>' +
            '<input type="text" class="form-control form-control-sm mb-1 ib-title" placeholder="Title" value="' + escHtml(item.title || '') + '">' +
            '<textarea class="form-control form-control-sm mb-1 ib-desc" rows="2" placeholder="Description">' + escHtml(item.description || '') + '</textarea>' +
            '<button type="button" class="btn btn-xs btn-danger remove-iconbox-item"><i class="fas fa-trash"></i> Remove</button>' +
        '</div>';
    }

    function initIconBoxEditor(block) {
        $(document).off('click', '#add-iconbox-item').on('click', '#add-iconbox-item', function() {
            var $list = $('#iconbox-items-list');
            var count = $list.find('.ib-row').length;
            $list.append(buildIconBoxRow({ icon: 'fas fa-star', title: '', description: '' }, count));
        });
        $(document).off('click', '.remove-iconbox-item').on('click', '.remove-iconbox-item', function() {
            $(this).closest('.ib-row').remove();
        });
        $(document).off('input.ibicon', '.ib-icon').on('input.ibicon', '.ib-icon', function() {
            var val = $(this).val();
            $(this).closest('.ib-row').find('.ib-icon-preview i').attr('class', val);
        });
    }

    // =========================================================================
    // Register built-in block types
    // =========================================================================

    PageEditor.registerBlockType('text', {
        icon: 'fa-font',
        label: 'Text',
        defaults: { content: { blocks: [] }, settings: {} },
        renderPreview: function(block) {
            var blocks = (block.content && block.content.blocks) ? block.content.blocks : [];
            if (!blocks.length) return '<em>Empty text block</em>';
            var html = '';
            blocks.forEach(function(b) {
                if (!b.data) return;
                var t = b.type || '';
                var text = b.data.text || '';
                if (t === 'paragraph') {
                    html += '<p>' + text + '</p>';
                } else if (t === 'header') {
                    var level = b.data.level || 2;
                    html += '<h' + level + '>' + text + '</h' + level + '>';
                } else if (t === 'list') {
                    var tag = (b.data.style === 'ordered') ? 'ol' : 'ul';
                    var items = (b.data.items || []);
                    html += '<' + tag + '>' + items.map(function(i) { return '<li>' + (typeof i === 'string' ? i : (i.content || '')) + '</li>'; }).join('') + '</' + tag + '>';
                } else if (t === 'quote') {
                    html += '<blockquote>' + text + '</blockquote>';
                } else if (t === 'table') {
                    var trows = b.data.content || [];
                    html += '<table>' + trows.map(function(r) { return '<tr>' + (r || []).map(function(c) { return '<td>' + c + '</td>'; }).join('') + '</tr>'; }).join('') + '</table>';
                } else if (t === 'image') {
                    html += '<img src="' + escHtml(b.data.file && b.data.file.url ? b.data.file.url : '') + '" style="max-width:100%;">';
                } else {
                    html += '<p>' + text + '</p>';
                }
            });
            return '<div class="admin-preview-text">' + html + '</div>';
        },
        renderEditor: function(block) {
            return '<div class="mb-2"><button type="button" class="btn btn-sm btn-outline-info" id="editorjs-media-btn"><i class="fas fa-images mr-1"></i> Insert from Media Library</button></div>' +
                '<div id="block-editorjs" style="border:1px solid #ced4da;border-radius:4px;min-height:200px;padding:8px;"></div>';
        },
        initEditor: function(block) {
            initEditorJS(block);
            $('#editorjs-media-btn').off('click').on('click', function() {
                openMediaBrowser(function(media) {
                    if (editorJsInstance) {
                        editorJsInstance.blocks.insert('image', {
                            file: { url: media.url },
                            caption: media.alt || '',
                            withBorder: false,
                            stretched: false,
                            withBackground: false
                        });
                    }
                });
            });
        },
        collectData: function(block) {
            if (editorJsInstance) {
                return editorJsInstance.save().then(function(data) {
                    return { content: data, settings: {} };
                });
            }
            return Promise.resolve({ content: block.content, settings: block.settings });
        }
    });

    PageEditor.registerBlockType('image', {
        icon: 'fa-image',
        label: 'Image',
        defaults: { content: { url: '', alt: '', caption: '' }, settings: { link: '', max_width: '100%' } },
        renderPreview: function(block) {
            var url = block.content && block.content.url ? block.content.url : '';
            if (!url) return '<em>No image set</em>';
            var caption = block.content && block.content.caption ? block.content.caption : '';
            return '<div><img src="' + escHtml(url) + '" style="max-width:100%;max-height:200px;border-radius:4px;">' +
                (caption ? '<div style="font-size:0.85em;color:#555;margin-top:4px;">' + escHtml(caption) + '</div>' : '') + '</div>';
        },
        renderEditor: function(block) {
            var url = block.content && block.content.url ? block.content.url : '';
            var alt = block.content && block.content.alt ? block.content.alt : '';
            var caption = block.content && block.content.caption ? block.content.caption : '';
            var link = block.settings && block.settings.link ? block.settings.link : '';
            var maxWidth = block.settings && block.settings.max_width ? block.settings.max_width : '100%';
            return '<div class="form-group"><label>Image</label>' +
                '<div class="d-flex align-items-stretch mb-2" style="gap:10px;">' +
                    '<div class="flex-grow-1 needsclick block-img-dz" id="block-image-dropzone"></div>' +
                    '<button type="button" class="btn btn-outline-info" id="browse-media-btn" style="white-space:nowrap;"><i class="fas fa-images d-block mb-1" style="font-size:1.2em;"></i> Browse<br>Media</button>' +
                '</div>' +
                '<small class="text-muted">Or enter URL manually:</small>' +
                '<input type="text" class="form-control mt-1" id="img-url" value="' + escHtml(url) + '" placeholder="https://...">' +
                '<div class="mt-2" id="img-preview">' + (url ? '<img src="' + escHtml(url) + '" style="max-height:100px;border-radius:4px;">' : '') + '</div>' +
                '</div>' +
                '<div class="form-group"><label>Alt Text</label><input type="text" class="form-control" id="img-alt" value="' + escHtml(alt) + '"></div>' +
                '<div class="form-group"><label>Caption</label><input type="text" class="form-control" id="img-caption" value="' + escHtml(caption) + '"></div>' +
                '<div class="form-group"><label>Link URL (optional)</label><input type="text" class="form-control" id="img-link" value="' + escHtml(link) + '"></div>' +
                '<div class="form-group"><label>Max Width</label><input type="text" class="form-control" id="img-maxwidth" value="' + escHtml(maxWidth) + '" placeholder="100%"></div>';
        },
        initEditor: function(block) {
            initBlockImageDropzone();
            $('#browse-media-btn').off('click').on('click', function() {
                openMediaBrowser(function(media) {
                    $('#img-url').val(media.url);
                    if (media.alt) $('#img-alt').val(media.alt);
                    $('#img-preview').html('<img src="' + escHtml(media.url) + '" style="max-height:100px;border-radius:4px;">');
                });
            });
        },
        collectData: function(block) {
            return {
                content: {
                    url: $('#img-url').val(),
                    alt: $('#img-alt').val(),
                    caption: $('#img-caption').val()
                },
                settings: {
                    link: $('#img-link').val(),
                    max_width: $('#img-maxwidth').val() || '100%'
                }
            };
        }
    });

    PageEditor.registerBlockType('video', {
        icon: 'fa-video',
        label: 'Video',
        defaults: { content: { url: '' }, settings: { aspect_ratio: '16:9' } },
        renderPreview: function(block) {
            var videoUrl = (block.content && block.content.url) ? block.content.url : '';
            if (!videoUrl) return '<em>No URL set</em>';
            var embedUrl = '';
            var ytMatch = videoUrl.match(/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/);
            var viMatch = videoUrl.match(/vimeo\.com\/(?:video\/)?(\d+)/);
            if (ytMatch) embedUrl = 'https://www.youtube.com/embed/' + ytMatch[1];
            else if (viMatch) embedUrl = 'https://player.vimeo.com/video/' + viMatch[1];
            if (embedUrl) {
                return '<div style="max-width:400px;position:relative;padding-bottom:56.25%;height:0;overflow:hidden;">' +
                    '<iframe src="' + escHtml(embedUrl) + '" style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" allowfullscreen></iframe></div>';
            }
            return escHtml(videoUrl);
        },
        renderEditor: function(block) {
            var url = block.content && block.content.url ? block.content.url : '';
            return '<div class="form-group"><label>Video URL</label>' +
                '<input type="text" class="form-control" id="video-url" value="' + escHtml(url) + '" placeholder="https://www.youtube.com/watch?v=...">' +
                '<small class="text-muted">Paste a YouTube or Vimeo URL</small></div>' +
                '<div id="video-preview" class="mt-2">' + getVideoPreviewHtml(url) + '</div>';
        },
        initEditor: function(block) {
            // Video URL live preview binding is handled in bindFormEvents
        },
        collectData: function(block) {
            return {
                content: { url: $('#video-url').val() },
                settings: block.settings
            };
        }
    });

    // --- Imported sections -------------------------------------------------
    //
    // A section copied from another site is raw markup: the page builder could
    // only ever show its owner a textarea full of HTML, so the one thing they
    // wanted to do — change the wording, swap a picture — was the one thing
    // they could not. The importer marks each piece of wording, each image and
    // each link with data-vela-field, and these helpers turn those marks into
    // an ordinary form, with the section itself rendered beside it under the
    // page's real stylesheet.

    var _htmlDoc = null;          // parsed copy of the block being edited
    var _htmlHasFields = false;   // does this block carry importer field marks?
    var _htmlHidden = [];         // ids of parts the user chose not to show
    var _htmlMoveUnavailable = false; // the preview could not load SortableJS
    var _htmlSelected = null;     // path of the part held in the preview, if any
    var _htmlTab = 'content';     // which tab of the panel is open
    var _htmlPartStyles = {};     // per-part styling, keyed by field or part id
    var _htmlPreviewTimer = null;

    /**
     * Bring a section imported before these controls existed up to date.
     *
     * The marks the form and the design panel read — the block id, the grids,
     * the fields — are added at import time. Sections copied before that carry
     * none of them, and their owner would have to re-copy the page to get an
     * editable one. They are cheap to work out from the markup itself, so an
     * old section repairs itself the first time it is opened.
     */
    /** What a layout framework puts on something meant to be a column. */
    var COLUMN_CLASS = /(^|[\s:])(col-span|col-start|col-end|basis-|flex-1|w-1\/2|w-1\/3|w-2\/3)/;

    function upgradeImportedBlock(doc) {
        var wrapper = doc.querySelector('[data-vela-block]')
            || doc.querySelector('[class*="vela-import-"]');
        if (!wrapper) return doc;

        if (!wrapper.hasAttribute('data-vela-block')) {
            wrapper.setAttribute('data-vela-block', 'b' + Math.abs(hashString(wrapper.innerHTML)).toString(16).slice(0, 10));
        }

        // Rows the layout controls can act on. Two kinds count: a run of
        // identical cards, and a row of columns — a heading beside a form is
        // the arrangement people most want to change, and its two children
        // look nothing alike, so matching on sameness alone left exactly that
        // row with no control at all.
        var gridIndex = 0;
        Array.prototype.forEach.call(doc.querySelectorAll('[data-vela-grid]'), function(el) {
            var n = parseInt((el.getAttribute('data-vela-grid') || '').slice(1), 10);
            if (n >= gridIndex) gridIndex = n;
        });

        Array.prototype.forEach.call(wrapper.querySelectorAll('*'), function(el) {
            if (el.hasAttribute('data-vela-grid')) return;

            var kids = el.children;
            if (kids.length < 2) return;

            var signature = null;
            var same = true;
            var columns = 0;
            for (var i = 0; i < kids.length; i++) {
                var current = kids[i].tagName + '|' + Array.prototype.slice.call(kids[i].classList).sort().slice(0, 4).join(' ');
                if (signature === null) signature = current;
                else if (signature !== current) same = false;

                if (COLUMN_CLASS.test(kids[i].className || '')) columns++;
            }

            if (!((same && signature) || columns >= 2)) return;

            el.setAttribute('data-vela-grid', 'g' + (++gridIndex));
            el.setAttribute('data-vela-grid-count', String(kids.length));

            // Each child of a row is a card. Naming it is what lets the editor
            // call it a card, let it be clicked as a whole, and let it be taken
            // out — before this the only handle on one was whatever happened to
            // be marked inside it.
            for (var c = 0; c < kids.length; c++) {
                kids[c].setAttribute('data-vela-card', 'c' + gridIndex + '-' + (c + 1));
            }
        });

        if (!doc.querySelector('[data-vela-field]')) {
            var fieldIndex = 0;
            Array.prototype.forEach.call(wrapper.querySelectorAll('*'), function(el) {
                var tag = el.tagName.toLowerCase();
                var kinds = [];
                if (tag === 'img') kinds.push('image');
                if (tag === 'a' && el.getAttribute('href')) kinds.push('link');
                if (['input', 'select', 'textarea'].indexOf(tag) > -1
                    && ['hidden', 'submit', 'button', 'reset', 'image'].indexOf((el.getAttribute('type') || '').toLowerCase()) === -1) {
                    kinds.push('control');
                }

                var childTags = Array.prototype.map.call(el.children, function(c) { return c.tagName.toLowerCase(); });
                var breaksOnly = childTags.length > 0 && childTags.every(function(t) { return t === 'br'; });
                var text = (el.textContent || '').trim();
                if ((childTags.length === 0 || breaksOnly) && text
                    && ['script', 'style', 'img', 'br', 'input'].indexOf(tag) === -1) {
                    kinds.push('text');
                    if (breaksOnly) el.setAttribute('data-vela-field-multiline', '1');
                }

                if (kinds.indexOf('link') === -1 && couldCarryALink(el, kinds)) kinds.push('linkable');

                if (!kinds.length) return;
                el.setAttribute('data-vela-field', 'f' + (++fieldIndex));
                el.setAttribute('data-vela-field-kind', kinds.join(' '));
            });
        }

        wrapLooseText(doc, wrapper);

        return doc;
    }

    /**
     * Whether a link can be put on this element without breaking the markup.
     *
     * The mirror of SectionImporter::couldCarryALink — an <a> inside an <a> is
     * not repaired by the browser, it closes the outer one early, so anything
     * already inside a link is left alone. A card or a bullet qualifies as a
     * whole, because clicking the whole card is what a visitor expects; where
     * it already holds a link of its own, that link is the answer.
     */
    function couldCarryALink(el, kinds) {
        for (var node = el.parentNode; node && node.tagName; node = node.parentNode) {
            if (node.tagName.toLowerCase() === 'a') return false;
        }

        if (el.hasAttribute('data-vela-card') || el.tagName.toLowerCase() === 'li') {
            return !el.querySelector('a[href]');
        }

        return kinds.indexOf('text') > -1 || kinds.indexOf('image') > -1;
    }

    var LOOSE_TEXT_SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, OPTION: 1, TITLE: 1, NOSCRIPT: 1 };

    /**
     * Give wording that shares its element with a tag something to be edited by.
     *
     * A line like `<strong>24x faster</strong> builds` marks only the bold part:
     * "builds" is a bare run of text with no element of its own, so there is
     * nothing to attach an id to and nothing to click. Half a sentence was
     * editable and the other half was not, with no way to tell which until you
     * tried. Wrapping each loose run in a span of its own makes the whole line
     * reachable while leaving the wording, the tags and the spacing as they were.
     *
     * Runs once per open and does nothing the second time: a wrapped run is no
     * longer loose.
     */
    function wrapLooseText(doc, wrapper) {
        var next = 1;
        Array.prototype.forEach.call(doc.querySelectorAll('[data-vela-field]'), function(el) {
            var n = parseInt((el.getAttribute('data-vela-field') || '').slice(1), 10);
            if (n >= next) next = n + 1;
        });

        Array.prototype.forEach.call(wrapper.querySelectorAll('*'), function(el) {
            if (LOOSE_TEXT_SKIP[el.tagName] || el.hasAttribute('data-vela-field')) return;
            // Only where text sits BESIDE a tag. An element holding nothing but
            // words is already a field in its own right.
            if (!el.children.length) return;

            Array.prototype.slice.call(el.childNodes).forEach(function(node) {
                if (node.nodeType !== 3) return;

                // The spaces around the words are what separate them from the
                // tag beside them; taken into the span they would be trimmed
                // away on the first edit and the line would close up.
                var parts = /^(\s*)([\s\S]*?)(\s*)$/.exec(node.nodeValue || '');
                if (!parts || !parts[2]) return;

                var span = doc.createElement('span');
                span.setAttribute('data-vela-field', 'f' + (next++));
                span.setAttribute('data-vela-field-kind', 'text');
                span.textContent = parts[2];

                var replacement = doc.createDocumentFragment();
                if (parts[1]) replacement.appendChild(doc.createTextNode(parts[1]));
                replacement.appendChild(span);
                if (parts[3]) replacement.appendChild(doc.createTextNode(parts[3]));
                el.replaceChild(replacement, node);
            });
        });

        return doc;
    }

    function hashString(value) {
        var hash = 0;
        for (var i = 0; i < (value || '').length; i++) {
            hash = ((hash << 5) - hash + value.charCodeAt(i)) | 0;
        }
        return hash;
    }

    /**
     * Put the editor's live preview in the left pane.
     *
     * Block types render one flat form, so the preview arrives inside it; the
     * dialog is split in two, and this hands the preview to the pane that
     * scrolls on its own. Block types without a preview leave the pane empty,
     * and it hides itself so the form gets the whole width.
     */
    /**
     * Empty the preview pane before a new editor is rendered.
     *
     * The pane keeps the iframe that was moved into it, and the next editor
     * renders a second element with the same id. Document order then made
     * `#vela-html-preview` resolve to the STALE one: the new preview was
     * written into the iframe that was about to be thrown away, so opening a
     * section a second time showed an empty pane.
     */
    function clearEditorPreviewPane() {
        $('#block-edit-preview').empty().attr('hidden', 'hidden');
    }

    function moveEditorPreviewIntoPane() {
        var $pane = $('#block-edit-preview');
        if (!$pane.length) return;

        var $preview = $('#block-edit-content').find('#vela-html-preview');
        $pane.empty();

        if (!$preview.length) {
            $pane.attr('hidden', 'hidden');
            return;
        }

        // The height belonged to the old single-column dialog; in the pane it
        // fills whatever room the screen gives it.
        $preview.css({ height: '100%', marginBottom: 0 }).appendTo($pane);
        $pane.removeAttr('hidden');
    }

    function parseBlockHtml(html) {
        var doc = new DOMParser().parseFromString(
            '<div id="vela-root">' + stripEditorFurniture(html || '') + '</div>',
            'text/html'
        );

        // A stray toolbar saved into the markup renders as a floating bar on
        // the live page; it belongs to the editor and never to the block.
        Array.prototype.forEach.call(doc.querySelectorAll('[data-vela-ui]'), function(el) {
            el.parentNode.removeChild(el);
        });

        return doc;
    }

    function serializeBlockHtml(doc) {
        var root = doc.getElementById('vela-root');
        return root ? root.innerHTML : '';
    }

    function fieldElements(doc) {
        return Array.prototype.slice.call(doc.querySelectorAll('[data-vela-field]'));
    }

    /** The page's own stylesheet, so the preview shows what a visitor sees. */
    function pageCustomCss() {
        var $css = $('#custom_css');
        return $css.length ? ($css.val() || '') : '';
    }

    /** The wording of one field, with its line breaks as newlines. */
    function fieldText(el) {
        if (!el.hasAttribute('data-vela-field-multiline')) {
            return (el.textContent || '').trim();
        }

        var holder = document.createElement('div');
        holder.innerHTML = (el.innerHTML || '').replace(/<br\s*\/?>/gi, '\n');
        return (holder.textContent || '').replace(/[ \t]+\n/g, '\n').trim();
    }

    /**
     * Write wording back into an element.
     *
     * textContent, never raw innerHTML: what is typed here is wording, not
     * markup, and must not be able to put tags into the page. A field that
     * held line breaks gets them back as <br> after escaping, so the only
     * markup that can appear is the break itself.
     */
    // What formatting a piece of wording is allowed to carry. Anything else a
    // browser or a paste puts there is unwrapped rather than dropped, so the
    // words survive even when the tag around them does not.
    var RICH_TAGS = { B: 1, STRONG: 1, I: 1, EM: 1, U: 1, S: 1, STRIKE: 1, BR: 1,
        A: 1, SUP: 1, SUB: 1, CODE: 1, MARK: 1 };
    var RICH_HREF = /^(https?:\/\/|mailto:|tel:|\/|#|\.\/|\.\.\/)/i;

    /**
     * Reduce edited wording to the formatting the editor is willing to keep.
     *
     * The preview is an editable copy of someone else's page: a paste brings
     * whatever was on the clipboard, and browsers still reach for style
     * attributes when asked to make something bold. Read here rather than
     * trusted, because this is the last point before it becomes the block.
     */
    /**
     * Take the toolbar's own text out of a page's wording.
     *
     * The bar reads \u25B2 Block \u2725 + \u00D7 — an up arrow, the name of the part, a
     * grip, a copy button and a close button. Caught by an earlier read it
     * ended up inside the words themselves, so a heading on a live page reads
     * "Tools▲✥×". As plain text there is nothing structural left to key on;
     * the glyphs together are specific enough to be safe.
     */
    // The up arrow is hidden when there is nothing above the part, and the
    // name is empty for an unnamed one, so only the grip and the close button
    // are always there. That pair is the signature.
    var EDITOR_FURNITURE = /\u25B2?(?:Block|Group|Section|Row)?\u2725\+?\u00D7/g;

    function stripEditorFurniture(value) {
        return String(value === null || value === undefined ? '' : value).replace(EDITOR_FURNITURE, '');
    }

    function sanitizeRichHtml(html) {
        var box = document.createElement('div');
        box.innerHTML = html === null || html === undefined ? '' : String(html);

        Array.prototype.slice.call(box.querySelectorAll('script,style,iframe,object,embed,[data-vela-ui]'))
            .forEach(function(el) { el.parentNode.removeChild(el); });

        // The preview's own toolbar sits inside the part it belongs to, so a
        // read that catches it writes "Block✥×" into the page's wording — and
        // it is saved as plain text, where nothing downstream can tell it from
        // something a person typed.
        box.innerHTML = stripEditorFurniture(box.innerHTML);

        // Deepest first, so unwrapping a parent cannot leave a child unchecked.
        var all = Array.prototype.slice.call(box.querySelectorAll('*')).reverse();
        all.forEach(function(el) {
            if (!RICH_TAGS[el.tagName]) {
                while (el.firstChild) el.parentNode.insertBefore(el.firstChild, el);
                el.parentNode.removeChild(el);
                return;
            }

            var href = el.tagName === 'A' ? (el.getAttribute('href') || '') : '';
            Array.prototype.slice.call(el.attributes).forEach(function(attr) {
                el.removeAttribute(attr.name);
            });
            if (href && RICH_HREF.test(href)) el.setAttribute('href', href);
        });

        return box.innerHTML;
    }

    /** The wording of a field as markup, kept to what is allowed. */
    function fieldHtml(el) {
        return sanitizeRichHtml(el.innerHTML || '');
    }

    function setFieldHtml(el, html) {
        el.innerHTML = sanitizeRichHtml(html);
    }

    /** The same wording with its formatting taken off, for the form beside it. */
    function plainFromHtml(html, multiline) {
        var holder = document.createElement('div');
        holder.innerHTML = multiline
            ? String(html === null || html === undefined ? '' : html).replace(/<br\s*\/?>/gi, '\n')
            : String(html === null || html === undefined ? '' : html);
        var text = holder.textContent || '';
        return multiline ? text.replace(/[ \t]+\n/g, '\n').trim() : text.replace(/\s+/g, ' ').trim();
    }

    function setFieldText(el, value) {
        value = value === null || value === undefined ? '' : String(value);

        if (!el.hasAttribute('data-vela-field-multiline')) {
            el.textContent = value;
            return;
        }

        var escaper = document.createElement('div');
        escaper.textContent = value;
        el.innerHTML = escaper.innerHTML.replace(/\r?\n/g, '<br>');
    }

    function fieldLabel(el, kinds) {
        var tag = el.tagName.toLowerCase();
        if (kinds.indexOf('control') > -1) {
            var what = tag === 'textarea' ? 'Message box' : (tag === 'select' ? 'Dropdown' : 'Field');
            var named = el.getAttribute('name') || el.getAttribute('id') || '';
            return named ? what + ' — ' + named.replace(/[-_]/g, ' ') : what;
        }
        if (kinds.indexOf('image') > -1) return 'Image';
        if (/^h[1-6]$/.test(tag)) return 'Heading ' + tag.slice(1);
        if (tag === 'button' || (kinds.indexOf('link') > -1 && kinds.indexOf('text') > -1)) return 'Button / link';
        if (kinds.indexOf('link') > -1) return 'Link';
        if (tag === 'li') return 'List item';
        // Last, so that a bullet is still called a bullet: every child of a
        // row is a card, and a plain <ul> is a row like any other. Before the
        // children of a row were named, a card fell through to "Text" and read
        // as a stray paragraph among the ones inside it.
        if (el.hasAttribute('data-vela-card')) return 'Card';
        return 'Text';
    }

    function renderImportedFields(doc, root) {
        var els = fieldElements(doc);
        if (root && !root.hasAttribute('data-vela-block')) {
            // Only what is inside the part being worked on. A page's worth of
            // wording in one list — 257 rows on a pricing section — is a form
            // to hunt through, not a way to change a heading.
            els = els.filter(function(el) { return root === el || root.contains(el); });
        }
        if (!els.length) return '';

        var out = '<div id="vela-field-list">';
        els.forEach(function(el, i) {
            var id = el.getAttribute('data-vela-field');
            var kinds = (el.getAttribute('data-vela-field-kind') || '').split(/\s+/);
            var label = fieldLabel(el, kinds);
            var isHidden = _htmlHidden.indexOf(id) > -1;
            out += '<div class="form-group mb-3 pb-2" style="border-bottom:1px solid #f1f3f5;' + (isHidden ? 'opacity:.5;' : '') + '" data-field="' + escHtml(id) + '">' +
                '<div class="d-flex align-items-center justify-content-between mb-1">' +
                    '<label class="mb-0" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.03em;color:#6c757d;">' + escHtml(label) + '</label>' +
                    '<button type="button" class="btn btn-link btn-sm p-0 vela-field-visibility" data-target="' + escHtml(id) + '" ' +
                        'title="' + (isHidden ? 'Show this again' : 'Do not show this') + '">' +
                        '<i class="fas ' + (isHidden ? 'fa-eye-slash' : 'fa-eye') + '"></i>' +
                    '</button>' +
                '</div>';

            if (kinds.indexOf('image') > -1) {
                var src = el.getAttribute('src') || '';
                out += '<div class="d-flex align-items-center" style="gap:10px;">' +
                    '<img class="vela-field-thumb" src="' + escHtml(src) + '" style="width:64px;height:64px;object-fit:contain;background:#f8f9fa;border:1px solid #e9ecef;border-radius:4px;">' +
                    '<div class="flex-grow-1">' +
                        '<input type="text" class="form-control form-control-sm vela-field-src" value="' + escHtml(src) + '">' +
                        '<input type="text" class="form-control form-control-sm mt-1 vela-field-alt" value="' + escHtml(el.getAttribute('alt') || '') + '" placeholder="Alt text">' +
                    '</div>' +
                    '<button type="button" class="btn btn-outline-info btn-sm vela-field-browse" style="white-space:nowrap;"><i class="fas fa-images"></i> Browse</button>' +
                '</div>';

                // How big the picture runs. A width in per cent of the space it
                // sits in rather than in pixels: the same number then means the
                // same thing on a phone, and a picture cannot be dragged wider
                // than the column holding it.
                var width = imageWidth(el);
                out += '<div class="d-flex align-items-center mt-2" style="gap:8px;">' +
                    '<i class="fas fa-compress-arrows-alt text-muted" style="font-size:.75rem;"></i>' +
                    '<input type="range" class="form-range flex-grow-1 vela-field-width" min="10" max="100" step="5" value="' + width + '">' +
                    '<span class="text-muted vela-field-width-readout" style="font-size:.75rem;min-width:3.2em;text-align:right;">' + width + '%</span>' +
                '</div>';
            }

            if (kinds.indexOf('control') > -1) {
                out += '<input type="text" class="form-control form-control-sm vela-field-placeholder" ' +
                    'value="' + escHtml(el.getAttribute('placeholder') || '') + '" placeholder="Hint shown inside the field">';
            }

            if (kinds.indexOf('text') > -1) {
                var multiline = el.hasAttribute('data-vela-field-multiline');
                var text = fieldText(el);
                out += (multiline || text.length > 70
                    ? '<textarea class="form-control form-control-sm vela-field-text" rows="' + (multiline ? 2 : 3) + '">' + escHtml(text) + '</textarea>'
                    + (multiline ? '<small class="text-muted">Each new line becomes a line break.</small>' : '')
                    : '<input type="text" class="form-control form-control-sm vela-field-text" value="' + escHtml(text) + '">');
            }

            // A link box on everything that can carry one, not only on what was
            // already an <a>. A card, a bullet, a heading and a picture could
            // not be made to go anywhere before this.
            if (kinds.indexOf('link') > -1 || kinds.indexOf('linkable') > -1) {
                var anchor = linkAnchor(el);
                var href = anchor ? (anchor.getAttribute('href') || '') : '';
                var newTab = anchor ? anchor.getAttribute('target') === '_blank' : false;

                out += '<div class="input-group input-group-sm mt-1">' +
                    '<div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-link"></i></span></div>' +
                    '<input type="text" class="form-control vela-field-href" value="' + escHtml(href) + '" placeholder="/contact-us">' +
                    '</div>' +
                    '<div class="form-check mt-1" style="font-size:.8rem;">' +
                        '<input type="checkbox" class="form-check-input vela-field-newtab" id="newtab-' + escHtml(id) + '"' + (newTab ? ' checked' : '') + '>' +
                        '<label class="form-check-label text-muted" for="newtab-' + escHtml(id) + '">Open in a new tab</label>' +
                    '</div>';
            }

            out += '</div>';
        });

        return out + '</div>';
    }

    /**
     * The <a> carrying this element's link, if it has one.
     *
     * Either the element IS the link, or it was wrapped in one here — a wrap
     * this code made is marked, so a card sitting inside somebody else's <a>
     * is not mistaken for one and unwrapped out from under them.
     */
    function linkAnchor(el) {
        if (el.tagName.toLowerCase() === 'a') return el;
        var parent = el.parentNode;
        return parent && parent.tagName && parent.tagName.toLowerCase() === 'a'
            && parent.hasAttribute('data-vela-link-wrap') ? parent : null;
    }

    /**
     * Put a link on an element, take one off, or point it somewhere else.
     *
     * Where the element is not already an <a> it is wrapped in one. The wrapper
     * is display:contents, so it draws no box of its own: a card wrapped this
     * way stays the grid item it was, which it would not if the anchor sat
     * between it and the row. Colour and underline are inherited for the same
     * reason — a whole card turning blue is not what anyone meant by making it
     * clickable.
     */
    function applyLink(el, href, newTab) {
        var anchor = linkAnchor(el);

        if (!href) {
            if (!anchor) return;
            if (anchor === el) {
                anchor.removeAttribute('href');
                anchor.removeAttribute('target');
                anchor.removeAttribute('rel');
                return;
            }
            while (anchor.firstChild) anchor.parentNode.insertBefore(anchor.firstChild, anchor);
            anchor.parentNode.removeChild(anchor);
            return;
        }

        if (!anchor) {
            // An <a> inside an <a> is not repaired by the browser: it closes
            // the outer one early and the rest of the card stops responding.
            // The outer link wins, since it is the one holding this element.
            for (var node = el.parentNode; node && node.tagName; node = node.parentNode) {
                if (node.tagName.toLowerCase() === 'a') return;
            }

            anchor = el.ownerDocument.createElement('a');
            anchor.setAttribute('data-vela-link-wrap', '1');
            anchor.setAttribute('style', 'display:contents;color:inherit;text-decoration:inherit;');
            el.parentNode.insertBefore(anchor, el);
            anchor.appendChild(el);
        }

        anchor.setAttribute('href', href);

        if (newTab) {
            anchor.setAttribute('target', '_blank');
            // A page opened in a new tab can reach back at the one that opened
            // it through window.opener unless this says otherwise.
            anchor.setAttribute('rel', 'noopener noreferrer');
        } else {
            anchor.removeAttribute('target');
            anchor.removeAttribute('rel');
        }
    }

    /** The width the slider shows: what was set here, or the full width. */
    function imageWidth(el) {
        var found = /(?:^|;)\s*width\s*:\s*(\d+(?:\.\d+)?)%/i.exec(el.getAttribute('style') || '');
        return found ? Math.round(parseFloat(found[1])) : 100;
    }

    /**
     * How wide the picture runs, as a share of the space it sits in.
     *
     * Per cent rather than pixels so that the same setting means the same thing
     * on a phone, and so a picture can never be made wider than the column
     * holding it. At full width the setting is removed rather than written, so
     * the section's own stylesheet is back in charge.
     */
    function applyImageWidth(el, percent) {
        if (!(percent >= 10 && percent <= 100)) return;

        var style = (el.getAttribute('style') || '')
            .replace(/(?:^|;)\s*(?:width|height)\s*:[^;]*/gi, '')
            .replace(/^\s*;+|;+\s*$/g, '');

        if (percent >= 100) {
            if (style) el.setAttribute('style', style);
            else el.removeAttribute('style');
            return;
        }

        el.setAttribute('style', (style ? style + ';' : '') + 'width:' + percent + '%;height:auto');
    }

    /** Copy what is in the form back into the parsed markup. */
    function applyImportedFields(doc) {
        $('#vela-field-list > [data-field]').each(function() {
            var $row = $(this);
            var el = doc.querySelector('[data-vela-field="' + $row.data('field') + '"]');
            if (!el) return;

            var $src = $row.find('.vela-field-src');
            if ($src.length) {
                el.setAttribute('src', $src.val());
                el.setAttribute('alt', $row.find('.vela-field-alt').val() || '');
            }
            var $text = $row.find('.vela-field-text');
            if ($text.length) {
                // Two ways in, and they must not fight. The preview leaves the
                // formatted markup on the row; the box beside it holds the same
                // wording with the formatting taken off. While those still
                // agree the markup is what counts, and the moment someone types
                // in the box they stop agreeing — which is the signal that the
                // wording was rewritten there, formatting and all.
                var rich = $row.attr('data-html');
                var multiline = el.hasAttribute('data-vela-field-multiline');
                if (rich !== undefined && plainFromHtml(rich, multiline) === $text.val()) {
                    setFieldHtml(el, rich);
                } else {
                    $row.removeAttr('data-html');
                    setFieldText(el, $text.val());
                }
            }

            var $href = $row.find('.vela-field-href');
            if ($href.length) applyLink(el, $.trim($href.val() || ''), $row.find('.vela-field-newtab').is(':checked'));

            var $width = $row.find('.vela-field-width');
            if ($width.length) applyImageWidth(el, parseInt($width.val(), 10));

            var $placeholder = $row.find('.vela-field-placeholder');
            if ($placeholder.length) {
                if ($placeholder.val()) el.setAttribute('placeholder', $placeholder.val());
                else el.removeAttribute('placeholder');
            }
        });

        return doc;
    }

    /**
     * What the preview treats as one part of the section.
     *
     * One answer shared by everything the preview offers — dragging a part,
     * taking one out — because pointing at a card and getting two different
     * answers depending on which control you reach for is the feature failing.
     *
     * This runs in the preview, which is its own window, so it is carried
     * there as source text rather than shared as functions.
     */
    var PREVIEW_PART_HELPERS =
        // The design panel keeps its rules in a <style> inside the wrapper, and
        // the preview adds a toolbar of its own; counting raw children would
        // read either as part of the section. Only what a reader would call a
        // part counts here. Positions still travel as raw child indices, which
        // is what the block's own document is indexed by.
        'function parts(el){return Array.prototype.filter.call(el.children,function(c){' +
            'var t=c.tagName;' +
            'return t!=="STYLE"&&t!=="SCRIPT"&&!c.hasAttribute("data-vela-ui");});}' +
        // The part under the pointer, and the run of siblings it belongs to.
        //
        // A pointer lands on the innermost element it can — the span inside a
        // heading, the <strong> inside a card. What someone means by "this
        // part" is the outermost thing that still has something beside it, so
        // the climb stops at the first element whose parent holds more than one
        // part. Wording being edited is never taken apart further than the
        // field itself.
        // A section can be one heading and nothing else — a hero, nine wrappers
        // deep around a single <h1>. Nothing there has a sibling, so the climb
        // finds no part and the heading, the only thing in the section, could
        // not be pointed at. The lone content of such a chain is that section's
        // one part.
        // Boxes only on the way down. Counting a heading as one more box steps
        // into it, and a headline broken over two lines makes the <br> inside
        // it the section's one part — so the heading itself answered to nothing.
        'var BOX=/^(DIV|SECTION|MAIN|ARTICLE|HEADER|FOOTER|ASIDE|NAV|FORM|FIGURE|UL|OL|DL|TABLE|TBODY|TR)$/;' +
        'var solo=root;' +
        'while(parts(solo).length===1&&BOX.test(parts(solo)[0].tagName)' +
            '&&parts(parts(solo)[0]).length)solo=parts(solo)[0];' +
        'solo=parts(solo).length===1?parts(solo)[0]:null;' +
        'function partAt(node){' +
            'var el=node&&node.closest?(node.closest("[data-vela-editable]")||node):node;' +
            'while(el&&el!==root){' +
                'var up=el.parentElement;if(!up)return null;' +
                'if((up===root||root.contains(up))&&parts(up).length>=2)return el;' +
                'el=up;}' +
            'return (solo&&node&&solo.contains(node))?solo:null;}' +
        'function pathOf(el){var path=[];' +
            'while(el&&el!==root){var up=el.parentElement;if(!up)return null;' +
            'path.unshift(Array.prototype.indexOf.call(up.children,el));el=up;}' +
            'return el===root?path.join("/"):null;}' +
        'function nodeAt(path){var node=root;' +
            'var steps=String(path).split("/").filter(function(s){return s!=="";});' +
            'for(var i=0;i<steps.length;i++){node=node.children[parseInt(steps[i],10)];if(!node)return null;}' +
            'return node;}';

    /**
     * Where the preview gets SortableJS from.
     *
     * The preview is its own document, so the copy the editor page already
     * loaded is not in scope there. Reading the tag the page used keeps one
     * source of truth: a site that self-hosts the library gets its own copy in
     * the preview too, rather than one quietly coming from a CDN.
     */
    function sortableScriptUrl() {
        var tag = document.querySelector('script[src*="Sortable"]');
        return tag ? tag.src : 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js';
    }

    /**
     * Fold the form back into the block and put the result where saving reads it.
     *
     * Separate from drawing the preview because typing in the preview must not
     * redraw it: the document is replaced wholesale, which throws the caret to
     * the top of the section between one letter and the next. The wording still
     * has to reach the field the block is saved from, though, or an inline edit
     * would look right on screen and be gone after Save.
     */
    function writeImportedHtml() {
        if (!_htmlDoc) return '';
        var html = serializeBlockHtml(applyDesign(applyImportedFields(_htmlDoc), collectDesign()));
        $('#html-content').val(html);
        // Every change to the section passes through here — typing in the
        // preview, a drag, a design choice, a part left out — so this is where
        // the section's undo steps come from.
        noteBlockHistory();
        return html;
    }

    function refreshImportedPreview() {
        var $frame = $('#vela-html-preview');
        if (!$frame.length || !_htmlDoc) return;

        var html = writeImportedHtml();

        // Everything the preview offers is on from the moment it opens. It used
        // to be three modes behind two buttons, so rearranging meant leaving
        // whatever you were doing, pressing a button, and pressing it again to
        // get back — for a section you can see and want to change.
        //
        // The gestures are kept apart by what they act on rather than by a
        // mode: wording answers to the caret, a part answers to the small bar
        // that appears at its corner when the pointer is over it.
        var previewCss = '<style>' +
            '[data-vela-editable]{outline:1px dashed rgba(50,31,219,.35);outline-offset:2px;cursor:text}' +
            '[data-vela-editable]:hover{outline-color:rgba(50,31,219,.75)}' +
            '[data-vela-editable]:focus{outline:2px solid #321fdb;outline-offset:2px;' +
                'background:rgba(50,31,219,.05)}' +
            '[data-vela-pick-image]{cursor:pointer}' +
            '[data-vela-pick-image]:hover{outline:2px solid #321fdb;outline-offset:-2px}' +
            // Dashed while merely pointed at, solid once held, so it is clear
            // which one a drag or a delete would act on.
            '[data-vela-hot]{outline:2px dashed #0d6efd !important;outline-offset:-2px}' +
            '[data-vela-pinned]{outline:2px solid #0d6efd !important;outline-offset:-2px;' +
                'background:rgba(13,110,253,.04)}' +
            '.vela-drag-ghost{opacity:.35}' +
            // The bar is fixed, so it sits over the section without taking part
            // in its layout — a card that suddenly grew a toolbar in the flow
            // would reflow the very thing being pointed at.
            '[data-vela-ui]{position:fixed;z-index:2147483647;display:flex;gap:2px;' +
                'font:12px/1 system-ui,sans-serif}' +
            '[data-vela-ui] button,[data-vela-ui] .vela-grip{width:22px;height:22px;padding:0;border:0;' +
                'cursor:pointer;color:#fff;background:#0d6efd;border-radius:3px;text-align:center;' +
                'font:13px/22px system-ui,sans-serif;-webkit-user-select:none;user-select:none}' +
            '.vela-fmt{gap:2px}' +
            '.vela-fmt button{width:26px;height:26px;background:#212529;font:13px/26px system-ui,sans-serif}' +
            '.vela-fmt button b,.vela-fmt button i,.vela-fmt button u,.vela-fmt button s{font-size:13px}' +
            '[data-vela-ui] .vela-name{height:22px;padding:0 7px;background:#0d6efd;color:#fff;' +
                'border-radius:3px;font:11px/22px system-ui,sans-serif;white-space:nowrap;' +
                '-webkit-user-select:none;user-select:none}' +
            '[data-vela-ui] button.vela-drop{background:#dc3545}' +
            '[data-vela-ui] .vela-grip{cursor:grab}' +
            // The copied form's controls are real: they take typing that goes
            // nowhere. Harmless while nothing here was editable, misleading now
            // that the wording around them is.
            '[data-vela-block] input,[data-vela-block] select,[data-vela-block] textarea' +
                '{pointer-events:none}' +
            '</style>';

        var previewJs =
            '<script src="' + escHtml(sortableScriptUrl()) + '"><\/script>' +
            '<script>(function(){' +
                'var root=document.querySelector("[data-vela-block]");if(!root)return;' +
                'var picked=' + JSON.stringify(_htmlSelected) + ';' +
                PREVIEW_PART_HELPERS +

                // --- the wording ------------------------------------------
                // Read from a copy with the editor's own furniture taken out.
                // Moving the one bar aside and putting it back worked until
                // something else of the editor's was inside too, and what it
                // missed was saved as plain text — a heading on a live page
                // reading "Tools▲✥×", with nothing left to tell it from words
                // somebody typed.
                'function send(el){' +
                    'var multi=el.hasAttribute("data-vela-field-multiline");' +
                    'var copy=el.cloneNode(true);' +
                    'Array.prototype.forEach.call(copy.querySelectorAll("[data-vela-ui]"),' +
                        'function(ui){ui.parentNode.removeChild(ui);});' +
                    'var html=copy.innerHTML;' +
                    'window.parent.postMessage({velaText:{' +
                        'field:el.getAttribute("data-vela-field"),html:html,multiline:multi}},"*");}' +
                'var timer=null;' +
                'Array.prototype.forEach.call(root.querySelectorAll("[data-vela-field]"),function(el){' +
                    'var kinds=(el.getAttribute("data-vela-field-kind")||"").split(/\\s+/);' +
                    'if(kinds.indexOf("image")>-1){' +
                        'el.setAttribute("data-vela-pick-image","");' +
                        'el.addEventListener("click",function(e){e.preventDefault();e.stopPropagation();' +
                            'window.parent.postMessage({velaImage:{' +
                                'field:el.getAttribute("data-vela-field")}},"*");});' +
                        'return;}' +
                    'if(kinds.indexOf("text")===-1)return;' +
                    'el.setAttribute("data-vela-editable","");' +
                    // Pasting into a plain contenteditable brings the source
                    // page's markup with it; plaintext-only keeps the section's
                    // own styling. Not everywhere supports it, hence the check.
                    // Rich, not plaintext-only: bold, italic and links are the
                    // point. What comes back is cut down to a short list of
                    // inline tags on the way out, so a paste cannot bring the
                    // clipboard's styling into the section.
                    'el.contentEditable="true";' +
                    'el.addEventListener("keydown",function(e){' +
                        'if(e.key==="Enter"&&!el.hasAttribute("data-vela-field-multiline"))e.preventDefault();});' +
                    'el.addEventListener("input",function(){' +
                        // The wording itself is held back a moment so the
                        // section is not rebuilt on every keystroke, but the
                        // fact that someone is typing has to leave immediately:
                        // clicking Cancel a moment later closes the dialog in
                        // the same tick, and a message still sitting behind the
                        // debounce arrives to an empty room.
                        'window.parent.postMessage({velaTouched:true},"*");' +
                        'clearTimeout(timer);timer=setTimeout(function(){send(el);},250);});' +
                    // One pending timer covers every field, so moving to the
                    // next one would cancel the last edit before it was sent.
                    'el.addEventListener("blur",function(){clearTimeout(timer);send(el);});' +
                '});' +

                // A copied section is full of real links, and following one
                // leaves the editor showing somebody else's page.
                'document.addEventListener("click",function(e){' +
                    'var a=e.target&&e.target.closest?e.target.closest("a"):null;' +
                    'if(a)e.preventDefault();' +
                '},true);' +

                // --- formatting the selected words ------------------------
                //
                // A bar that appears over a selection, the way one does in a
                // word processor. Styling a whole paragraph is a different job
                // from making three words of it bold, and only the element as a
                // whole could be reached before.
                'try{document.execCommand("styleWithCSS",false,false);}catch(err){}' +
                'var fmt=document.createElement("div");' +
                'fmt.setAttribute("data-vela-ui","");fmt.setAttribute("contenteditable","false");' +
                'fmt.className="vela-fmt";fmt.style.display="none";' +
                'fmt.innerHTML=' +
                    '\'<button data-cmd="bold" title="Bold"><b>B</b></button>\'+' +
                    '\'<button data-cmd="italic" title="Italic"><i>I</i></button>\'+' +
                    '\'<button data-cmd="underline" title="Underline"><u>U</u></button>\'+' +
                    '\'<button data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>\'+' +
                    '\'<button data-cmd="link" title="Link">&#128279;</button>\'+' +
                    '\'<button data-cmd="removeFormat" title="Clear formatting">&#10005;</button>\';' +
                'document.body.appendChild(fmt);' +

                'function editableOfSelection(){' +
                    'var s=document.getSelection();' +
                    'if(!s||!s.rangeCount||s.isCollapsed)return null;' +
                    'var node=s.anchorNode;' +
                    'node=node&&node.nodeType===1?node:(node?node.parentElement:null);' +
                    'return node&&node.closest?node.closest("[data-vela-editable]"):null;}' +

                'function placeFmt(){' +
                    'var host=editableOfSelection();' +
                    'if(!host){fmt.style.display="none";return;}' +
                    'var r=document.getSelection().getRangeAt(0).getBoundingClientRect();' +
                    'if(!r||(!r.width&&!r.height)){fmt.style.display="none";return;}' +
                    'fmt.style.display="flex";' +
                    'fmt.style.left=Math.max(2,r.left)+"px";' +
                    'fmt.style.top=Math.max(2,r.top-30)+"px";}' +

                'document.addEventListener("selectionchange",placeFmt);' +
                'window.addEventListener("scroll",placeFmt,true);' +

                // Pressing the bar must not take the selection away, which is
                // the very thing the command is about to act on.
                'fmt.addEventListener("mousedown",function(e){e.preventDefault();});' +
                'fmt.addEventListener("click",function(e){' +
                    'var btn=e.target&&e.target.closest?e.target.closest("[data-cmd]"):null;' +
                    'if(!btn)return;' +
                    'e.preventDefault();e.stopPropagation();' +
                    'var host=editableOfSelection();if(!host)return;' +
                    'var cmd=btn.getAttribute("data-cmd");' +
                    'if(cmd==="link"){' +
                        'var current="";' +
                        'var a=document.getSelection().anchorNode;' +
                        'a=a&&a.nodeType===1?a:(a?a.parentElement:null);' +
                        'a=a&&a.closest?a.closest("a"):null;' +
                        'if(a)current=a.getAttribute("href")||"";' +
                        'var url=window.prompt("Link address",current||"https://");' +
                        'if(url===null)return;' +
                        'if(url==="")document.execCommand("unlink",false,null);' +
                        'else document.execCommand("createLink",false,url);' +
                    '}else{document.execCommand(cmd,false,null);}' +
                    'send(host);placeFmt();' +
                '});' +

                // --- the parts --------------------------------------------
                'if(!window.Sortable){window.parent.postMessage({velaMoveUnavailable:1},"*");return;}' +

                'var bar=document.createElement("div");' +
                'bar.setAttribute("data-vela-ui","");bar.style.display="none";' +
                // Inside a contenteditable heading the bar is otherwise part of
                // what is being typed: the caret can land between its buttons
                // and a select-all takes it with the words.
                'bar.setAttribute("contenteditable","false");' +
                // The pointer picks the innermost part it is over, which is the
                // heading rather than the card holding it. Reaching the card by
                // aiming at its padding is a game of a few pixels, so the bar
                // carries the way out to whatever encloses the current part.
                'bar.innerHTML=' +
                    '\'<button class="vela-up" title="Select what holds this">&#9650;</button>\'+' +
                    // Naming what is held is the difference between a drag that
                    // does nothing and one that does what was meant: clicking
                    // the middle of a column lands on the heading inside it,
                    // and dragging that reorders within the column while the
                    // columns sit still, with nothing on screen to say why.
                    '\'<span class="vela-name"></span>\'+' +
                    // A span, not a button: the browser will not begin a
                    // native drag from inside a button, so the grip pressed
                    // like a control and moved nothing.
                    '\'<span class="vela-grip" title="Drag to move this">&#10021;</span>\'+' +
                    '\'<button class="vela-copy" title="Add another one like this">+</button>\'+' +
                    '\'<button class="vela-drop" title="Leave this out">&times;</button>\';' +
                'document.body.appendChild(bar);' +

                // A part chosen by pointing at it is only chosen for as long as
                // the pointer stays still — reaching for the grip already means
                // crossing other parts. Clicking one holds it: the bar stays on
                // it, and the choice outlives the redraw a drag causes, so the
                // same part can be moved twice without hunting for it again.
                'var hot=null,list=null,sortable=null,dragging=false,pinned=null;' +
                'function place(){' +
                    'if(!hot){bar.style.display="none";return;}' +
                    'var r=hot.getBoundingClientRect();' +
                    'bar.style.display="flex";' +
                    'bar.style.left=Math.max(2,r.left)+"px";' +
                    'bar.style.top=Math.max(2,r.top-24)+"px";}' +

                // One list is live at a time — whichever the pointer is in.
                // Binding every run of siblings at once puts a drop target
                // inside another, and dragging a heading moved the card around
                // it instead.
                'function bind(next){' +
                    'if(next===list)return;' +
                    'if(sortable){sortable.destroy();sortable=null;}' +
                    'if(list)list.removeAttribute("data-vela-sortable");' +
                    'list=next;' +
                    'if(!list)return;' +
                    'list.setAttribute("data-vela-sortable","");' +
                    'var from=null;' +
                    // Positions are read off the DOM rather than taken from
                    // Sortable's own indices, which skip whatever `filter`
                    // excludes and would then not line up with the document.
                    // The bar is deliberately NOT filtered: Sortable tests the
                    // filter against the pressed element and everything above
                    // it, so excluding the bar excluded the grip inside it and
                    // refused every drag. It needs no excluding anyway — it is
                    // never a child of the list, only of a part.
                    // forceFallback because the drag begins on the toolbar's
                    // grip, which floats over the section. The browser's own
                    // drag-and-drop starts from the element pressed, and from
                    // an overlay it started nothing at all; Sortable's own
                    // pointer handling follows the grip to the part it belongs
                    // to. It also gives the same behaviour everywhere rather
                    // than each browser's native drag.
                    'sortable=Sortable.create(list,{handle:".vela-grip",animation:150,' +
                        'forceFallback:true,fallbackTolerance:3,' +
                        'ghostClass:"vela-drag-ghost",filter:"style,script",' +
                        'onStart:function(e){bar.style.display="none";' +
                            'from=Array.prototype.indexOf.call(e.from.children,e.item);},' +
                        'onEnd:function(e){' +
                            'var to=Array.prototype.indexOf.call(e.to.children,e.item);' +
                            'if(from===null||to<0||to===from)return;' +
                            'var path=pathOf(e.to);if(path===null)return;' +
                            'window.parent.postMessage({velaMove:{container:path,from:from,to:to}},"*");}});}' +

                'function enclosing(){' +
                    'return (hot&&hot.parentElement)?partAt(hot.parentElement):null;}' +

                // What to call a part. Tags carry the answer for content; for
                // the boxes around it the class names of a copied site say
                // nothing a reader would recognise, so it is read off the
                // layout instead — a box sitting beside its neighbour is a
                // column, whatever the original called it.
                'var NAMES={H1:"Heading",H2:"Heading",H3:"Heading",H4:"Heading",H5:"Heading",' +
                    'H6:"Heading",P:"Text",IMG:"Image",A:"Link",BUTTON:"Button",UL:"List",OL:"List",' +
                    'LI:"List item",FORM:"Form",SECTION:"Section",FIGURE:"Figure",TABLE:"Table"};' +
                'function nameOf(el){' +
                    'if(!el)return "";' +
                    'if(NAMES[el.tagName])return NAMES[el.tagName];' +
                    'var up=el.parentElement,sibs=up?parts(up):[];' +
                    'if(sibs.length>1){' +
                        'var i=sibs.indexOf(el),other=sibs[i===0?1:i-1];' +
                        'var a=el.getBoundingClientRect(),b=other.getBoundingClientRect();' +
                        'if(Math.abs(a.top-b.top)<Math.max(8,Math.min(a.height,b.height)/2))return "Column";' +
                    '}' +
                    'return parts(el).length>1?"Group":"Block";}' +
                'function focusPart(el,pin){' +
                    'if(pin){' +
                        'if(pinned&&pinned!==el)pinned.removeAttribute("data-vela-pinned");' +
                        'pinned=el;' +
                        'if(pinned)pinned.setAttribute("data-vela-pinned","");' +
                        'window.parent.postMessage({velaSelect:el?pathOf(el):null},"*");' +
                    '}' +
                    'if(el===hot){place();return;}' +
                    'if(hot)hot.removeAttribute("data-vela-hot");' +
                    'hot=el;' +
                    // The bar lives INSIDE the part it belongs to, because
                    // Sortable only accepts a handle that is a descendant of
                    // the item being dragged — parked on the body the grip was
                    // just a button on top of the page and pressing it started
                    // nothing. Appended last and positioned fixed, it neither
                    // shifts the layout nor moves any sibling's index.
                    'if(hot){hot.setAttribute("data-vela-hot","");hot.appendChild(bar);' +
                        'bind(hot.parentElement);}' +
                    'else {document.body.appendChild(bar);bind(null);}' +
                    'var up=enclosing();' +
                    'var upBtn=bar.querySelector(".vela-up");' +
                    'upBtn.style.display=(up&&up!==hot)?"":"none";' +
                    'if(up&&up!==hot)upBtn.title="Select the "+nameOf(up).toLowerCase()+" holding this";' +
                    'bar.querySelector(".vela-name").textContent=nameOf(hot);' +
                    'place();}' +

                // A drag leaves the grip almost immediately and travels over
                // other parts on its way. Following the pointer there would
                // rebind the list — destroying the very Sortable in the middle
                // of the drag, which quietly cancelled every move.
                'bar.addEventListener("mousedown",function(){dragging=true;});' +
                'document.addEventListener("mouseup",function(){dragging=false;},true);' +
                'document.addEventListener("mouseover",function(e){' +
                    'if(dragging||pinned)return;' +
                    // Pointing at the bar is still pointing at the part it
                    // belongs to, or it would vanish on the way to its buttons.
                    'if(e.target&&e.target.closest&&e.target.closest("[data-vela-ui]"))return;' +
                    'focusPart(partAt(e.target));' +
                '},true);' +
                // Only when the pointer leaves the preview altogether. Watching
                // mouseleave from the document catches every element the
                // pointer leaves on its way anywhere, so the bar was dropped
                // the instant anyone set off towards its buttons.
                'document.addEventListener("mouseout",function(e){' +
                    'if(!e.relatedTarget&&!pinned)focusPart(null);' +
                '},true);' +

                // Clicking holds whatever is under the pointer. Wording keeps
                // its caret as well — being able to move a heading and rewrite
                // it are not different jobs to be in different states for.
                'document.addEventListener("click",function(e){' +
                    'if(dragging)return;' +
                    'if(e.target&&e.target.closest&&e.target.closest("[data-vela-ui]"))return;' +
                    'focusPart(partAt(e.target),true);' +
                '},false);' +
                // Escape steps out to whatever holds the current part, and only
                // lets go once there is nothing further out. Selecting the
                // heading inside a column when the column was meant is the
                // ordinary mistake, and stepping out is the fix for it — where
                // letting go entirely just means starting the aim again.
                'document.addEventListener("keydown",function(e){' +
                    'if(e.key!=="Escape"||!pinned)return;' +
                    'e.preventDefault();' +
                    'if(document.activeElement&&document.activeElement.isContentEditable)' +
                        'document.activeElement.blur();' +
                    'var up=enclosing();' +
                    'if(up&&up!==pinned)focusPart(up,true);else focusPart(null,true);' +
                '},true);' +

                // The choice survives the redraw a drop causes: the editor
                // hands back the position it should land on.
                'if(picked!==null){var re=nodeAt(picked);if(re)focusPart(re,true);}' +
                'window.addEventListener("scroll",place,true);' +
                'window.addEventListener("resize",place);' +

                'bar.querySelector(".vela-up").addEventListener("click",function(e){' +
                    'e.preventDefault();e.stopPropagation();' +
                    'var up=enclosing();' +
                    // Held, not merely shown: stepping out is a choice, and
                    // letting the next pointer movement undo it would make the
                    // button useless for reaching anything.
                    'if(up&&up!==hot)focusPart(up,true);' +
                '});' +
                'bar.querySelector(".vela-copy").addEventListener("click",function(e){' +
                    'e.preventDefault();e.stopPropagation();' +
                    'if(!hot)return;' +
                    'var path=pathOf(hot);if(path===null)return;' +
                    'window.parent.postMessage({velaDuplicate:path},"*");' +
                '});' +
                'bar.querySelector(".vela-drop").addEventListener("click",function(e){' +
                    'e.preventDefault();e.stopPropagation();' +
                    'if(!hot)return;' +
                    'var path=pathOf(hot);if(path===null)return;' +
                    'window.parent.postMessage({velaPick:path},"*");' +
                '});' +
            '})();<\/script>';

        // Once the pointer has been in the preview, the focus belongs to the
        // frame, and Ctrl+Z never reaches the editor around it — the shortcut
        // simply did nothing after a drag or after leaving a part out. The
        // frame passes it back out instead.
        //
        // Not while the caret is in wording being typed: there the browser's
        // own undo works letter by letter, and what it puts back arrives here
        // through the same input event any edit does.
        var keyJs = '<script>(function(){' +
            'document.addEventListener("keydown",function(e){' +
                'if(!(e.ctrlKey||e.metaKey))return;' +
                'var k=(e.key||"").toLowerCase();' +
                // Ctrl+S is worth passing out even mid-sentence: the browser's
                // own "save this file" dialog is never what was meant, and the
                // wording being typed is exactly what wants keeping.
                'if(k==="s"){e.preventDefault();window.parent.postMessage({velaSave:true},"*");return;}' +
                'if(k!=="z"&&k!=="y")return;' +
                'if(e.target&&e.target.isContentEditable)return;' +
                'e.preventDefault();' +
                'window.parent.postMessage({velaUndo:{redo:k==="y"||e.shiftKey}},"*");' +
            '},true);' +
        '})();<\/script>';

        // Redrawing the whole document would otherwise send the preview back to
        // the top on every keystroke, and on every drop — exactly when the part
        // being worked on is halfway down the section.
        var frame = $frame[0];
        var scroll = 0;
        try { scroll = (frame.contentWindow && frame.contentWindow.scrollY) || 0; } catch (e) {}
        frame.onload = function() {
            if (!scroll) return;
            try { frame.contentWindow.scrollTo(0, scroll); } catch (e) {}
        };

        // Styles go in the head; the script goes after the markup. It looks the
        // section up as it runs, and in the head it ran against a body that did
        // not exist yet — bailing on a null wrapper and binding nothing at all,
        // silently.
        frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8">' +
            '<style>body{margin:0}' + pageCustomCss() + '</style>' +
            previewCss + '</head><body>' + html + previewJs + keyJs + '</body></html>';
    }

    function scheduleImportedPreview() {
        clearTimeout(_htmlPreviewTimer);
        _htmlPreviewTimer = setTimeout(refreshImportedPreview, 350);
    }

    // --- Design controls for an imported section ---------------------------
    //
    // The wording could be edited but nothing about how it looked, which is
    // the next thing anyone reaches for: bigger heading, our colours, four
    // logos per row instead of six. The source stylesheet stays untouched;
    // the choices are stored as JSON on the wrapper and re-emitted as one
    // <style> block inside the section, so they always win over the imported
    // rules and can be changed or cleared again later.

    var DESIGN_FONTS = [
        ['', 'Keep the original'],
        ['inherit', "This site's font"],
        ['system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', 'System sans'],
        ['Georgia, "Times New Roman", serif', 'Serif'],
        ['"Courier New", ui-monospace, monospace', 'Monospace']
    ];

    /** Only values we generated ourselves are allowed back out into CSS. */
    function safeCssValue(value, pattern) {
        value = (value === null || value === undefined) ? '' : String(value).trim();
        if (value === '') return '';
        return pattern.test(value) ? value : '';
    }

    function readDesign(wrapper) {
        if (!wrapper) return {};
        try { return JSON.parse(wrapper.getAttribute('data-vela-design') || '{}') || {}; }
        catch (e) { return {}; }
    }

    var DESIGN_COLOUR = /^(#[0-9a-f]{3,8}|rgba?\([\d\s.,%]+\)|hsla?\([\d\s.,%]+\)|transparent|[a-z]{3,20})$/i;
    var DESIGN_LENGTH = /^-?\d{1,4}(px|rem|em|%|vw|vh)?$/i;

    /**
     * Drop anything that is not one of the values these controls produce.
     *
     * Run before the choices are stored, not only before they are written into
     * CSS: a rejected value kept in the saved attribute is a string from a
     * form sitting in the page's markup, and there is no reason to carry it.
     */
    function sanitizeDesign(design) {
        design = design || {};
        var clean = {};

        [['textColor', DESIGN_COLOUR], ['background', DESIGN_COLOUR], ['headingSize', DESIGN_LENGTH],
         ['bodySize', DESIGN_LENGTH], ['padding', DESIGN_LENGTH], ['maxWidth', DESIGN_LENGTH]].forEach(function(pair) {
            var value = safeCssValue(design[pair[0]], pair[1]);
            if (value) clean[pair[0]] = value;
        });

        var font = (design.font || '').replace(/[<>{};]/g, '').trim();
        if (font && DESIGN_FONTS.some(function(f) { return f[0] === font; })) clean.font = font;

        if (['left', 'center', 'right'].indexOf(design.align) > -1) clean.align = design.align;

        var grids = {};
        Object.keys(design.grids || {}).forEach(function(id) {
            var columns = parseInt(design.grids[id], 10);
            if (/^g\d+$/.test(id) && columns >= 1 && columns <= 8) grids[id] = columns;
        });
        if (Object.keys(grids).length) clean.grids = grids;

        // How each row is arranged. Kept to the handful of shapes the
        // controls offer, since these end up inside a CSS selector and a
        // declaration.
        var layout = {};
        Object.keys(design.layout || {}).forEach(function(id) {
            if (!/^g\d+$/.test(id)) return;
            var wanted = design.layout[id] || {};
            var kept = {};
            if (LAYOUT_SPLITS.indexOf(wanted.split) > -1) kept.split = wanted.split;
            if (LAYOUT_GAPS.indexOf(wanted.gap) > -1) kept.gap = wanted.gap;
            if (['start', 'center', 'end', 'stretch'].indexOf(wanted.align) > -1) kept.align = wanted.align;
            if (wanted.reverse === true || wanted.reverse === 'true') kept.reverse = true;
            if (Object.keys(kept).length) layout[id] = kept;
        });
        if (Object.keys(layout).length) clean.layout = layout;

        // Parts the user chose not to show. Ids only — anything else here
        // would end up inside a CSS selector.
        var hidden = (design.hidden || []).filter(function(id) {
            return /^[fp]\d+$/.test(id);
        });
        if (hidden.length) clean.hidden = hidden.slice(0, 200);

        // One paragraph in a different colour, one heading a size smaller: the
        // section-wide controls cannot say that, because they say it about
        // everything at once.
        var parts = {};
        Object.keys(design.parts || {}).slice(0, 300).forEach(function(id) {
            if (!/^[fp]\d+$/.test(id)) return;
            var from = design.parts[id] || {}, kept = {};

            [['color', DESIGN_COLOUR], ['background', DESIGN_COLOUR], ['size', DESIGN_LENGTH],
             ['lineHeight', /^\d{1,2}(\.\d{1,2})?$/], ['spaceBelow', DESIGN_LENGTH],
             ['padding', DESIGN_LENGTH], ['radius', DESIGN_LENGTH]].forEach(function(pair) {
                var value = safeCssValue(from[pair[0]], pair[1]);
                if (value) kept[pair[0]] = value;
            });
            if (PART_WEIGHTS.indexOf(from.weight) > 0) kept.weight = from.weight;
            if (from.style === 'italic' || from.style === 'normal') kept.style = from.style;
            if (['left', 'center', 'right'].indexOf(from.align) > -1) kept.align = from.align;

            if (Object.keys(kept).length) parts[id] = kept;
        });
        if (Object.keys(parts).length) clean.parts = parts;

        return clean;
    }

    var PART_WEIGHTS = ['', '300', '400', '500', '600', '700', '800'];
    var PART_SIZES = ['12px', '14px', '16px', '18px', '20px', '24px', '28px', '32px', '40px', '48px', '64px'];
    var PART_SPACES = ['0px', '4px', '8px', '12px', '16px', '24px', '32px', '48px'];
    var PART_LINES = ['1', '1.1', '1.25', '1.4', '1.6', '1.8', '2'];
    var PART_RADII = ['0px', '4px', '8px', '12px', '16px', '24px', '999px'];

    function designCss(blockId, design, grids) {
        var sel = '[data-vela-block="' + blockId + '"]';
        var css = '';

        var text = design.textColor || '';
        var background = design.background || '';
        var heading = design.headingSize || '';
        var body = design.bodySize || '';
        var padding = design.padding || '';
        var maxWidth = design.maxWidth || '';
        var font = design.font || '';
        var align = design.align || '';

        // Utility frameworks set colours and sizes on the element itself, so
        // these have to outrank a class rule of equal specificity.
        // The wrapper alone is not enough: the section inside paints its own
        // background over it, so the colour appeared to do nothing. Cards and
        // panels are divs and keep theirs.
        if (background) {
            css += sel + ',' + sel + ' :where(section,header,footer,main,article){background:' + background + ' !important}';
        }
        if (text) css += sel + ',' + sel + ' :where(p,span,li,a,h1,h2,h3,h4,h5,h6,div){color:' + text + ' !important}';
        if (font) css += sel + ',' + sel + ' *{font-family:' + font + ' !important}';
        if (heading) css += sel + ' :where(h1,h2,h3){font-size:' + heading + ' !important;line-height:1.15}';
        if (body) css += sel + ' :where(p,li,span):not(:has(*)){font-size:' + body + ' !important}';
        if (padding) css += sel + '{padding-top:' + padding + ' !important;padding-bottom:' + padding + ' !important}';
        if (maxWidth) css += sel + '{max-width:' + maxWidth + ';margin-left:auto;margin-right:auto}';
        if (align) css += sel + ',' + sel + ' :where(p,h1,h2,h3,h4){text-align:' + align + ' !important}';

        (design.hidden || []).forEach(function(id) {
            var attribute = id.charAt(0) === 'f' ? 'data-vela-field' : 'data-vela-part';
            css += sel + ' [' + attribute + '="' + id + '"]{display:none !important}';
        });

        // After the section-wide rules on purpose, though it hardly matters:
        // an id selector outranks the `:where()` those are written with, which
        // is what lets one paragraph disagree with the section around it.
        Object.keys(design.parts || {}).forEach(function(id) {
            var p = design.parts[id] || {};
            var attribute = id.charAt(0) === 'f' ? 'data-vela-field' : 'data-vela-part';
            var rules = '';

            var boxRules = '';
            // A box's own rules: painted on the element and nowhere else. Put
            // through the text rule below they would reach every span inside
            // it, and a background would be drawn behind each word instead of
            // behind the box.
            if (p.background) boxRules += 'background:' + p.background + ' !important;';
            if (p.padding) boxRules += 'padding:' + p.padding + ' !important;';
            if (p.radius) boxRules += 'border-radius:' + p.radius + ' !important;';
            if (boxRules) {
                css += sel + ' [' + attribute + '="' + id + '"]{' + boxRules + '}';
            }

            if (p.color) rules += 'color:' + p.color + ' !important;';
            if (p.size) rules += 'font-size:' + p.size + ' !important;';
            if (p.weight) rules += 'font-weight:' + p.weight + ' !important;';
            if (p.style) rules += 'font-style:' + p.style + ' !important;';
            if (p.align) rules += 'text-align:' + p.align + ' !important;';
            if (p.lineHeight) rules += 'line-height:' + p.lineHeight + ' !important;';
            if (p.spaceBelow) rules += 'margin-bottom:' + p.spaceBelow + ' !important;';
            if (!rules) return;

            // The wording of a heading often sits in a span inside it, and a
            // colour set on the heading alone loses to whatever the copied
            // stylesheet says about that span.
            css += sel + ' [' + attribute + '="' + id + '"],' +
                sel + ' [' + attribute + '="' + id + '"] :where(span,strong,em,b,i,a){' + rules + '}';
        });

        (grids || []).forEach(function(g) {
            var row = sel + ' [data-vela-grid="' + g.id + '"]';
            var columns = parseInt((design.grids || {})[g.id], 10);
            var layout = (design.layout || {})[g.id] || {};
            var declarations = '';

            // A split describes two columns; asking for a number of columns
            // describes any row. Either turns the row into a grid, so the
            // widths below can only ever apply to something laid out as one.
            if (layout.split) {
                var parts = layout.split.split('/');
                declarations += 'display:grid !important;grid-template-columns:' +
                    parseInt(parts[0], 10) + 'fr ' + parseInt(parts[1], 10) + 'fr !important;';
            } else if (columns >= 1 && columns <= 8) {
                declarations += 'display:grid !important;grid-template-columns:repeat(' +
                    columns + ',minmax(0,1fr)) !important;';
            }

            if (layout.gap) declarations += 'gap:' + layout.gap + ' !important;';
            if (layout.align) declarations += 'align-items:' + layout.align + ' !important;';

            if (declarations) css += row + '{' + declarations + '}';

            // Reversed by writing direction rather than by `order`, which only
            // grid and flex obey — this turns a row round whatever it is laid
            // out with, and the children are set back so their own text does
            // not read right to left.
            if (layout.reverse) {
                css += row + '{direction:rtl !important}' + row + ' > *{direction:ltr !important}';
            }
        });

        return css;
    }

    function applyDesign(doc, design) {
        var wrapper = doc.querySelector('[data-vela-block]');
        if (!wrapper) return doc;

        design = sanitizeDesign(design);
        var blockId = wrapper.getAttribute('data-vela-block');

        // A form control that is merely invisible is still submitted, and an
        // empty "Country" would arrive with every enquiry. Hidden controls are
        // switched off in the markup as well; shown ones get switched back on.
        Array.prototype.forEach.call(doc.querySelectorAll('[data-vela-hidden-control]'), function(el) {
            el.removeAttribute('disabled');
            el.removeAttribute('data-vela-hidden-control');
        });
        (design.hidden || []).forEach(function(id) {
            var attribute = id.charAt(0) === 'f' ? 'data-vela-field' : 'data-vela-part';
            var host = doc.querySelector('[' + attribute + '="' + id + '"]');
            if (!host) return;
            var controls = host.matches('input,select,textarea') ? [host] : host.querySelectorAll('input,select,textarea');
            Array.prototype.forEach.call(controls, function(control) {
                control.setAttribute('disabled', 'disabled');
                control.setAttribute('data-vela-hidden-control', '1');
            });
        });
        var grids = gridElements(doc);
        var css = designCss(blockId, design, grids);

        var hasChoice = Object.keys(design || {}).some(function(k) {
            return k === 'grids' ? Object.keys(design.grids || {}).length : design[k];
        });
        if (hasChoice) {
            wrapper.setAttribute('data-vela-design', JSON.stringify(design));
        } else {
            wrapper.removeAttribute('data-vela-design');
        }

        var style = wrapper.querySelector('style[data-vela-design-style]');
        if (!css) {
            if (style) style.parentNode.removeChild(style);
            return doc;
        }
        if (!style) {
            style = doc.createElement('style');
            style.setAttribute('data-vela-design-style', '');
            wrapper.insertBefore(style, wrapper.firstChild);
        }
        style.textContent = css;

        return doc;
    }

    function gridElements(doc) {
        return Array.prototype.slice.call(doc.querySelectorAll('[data-vela-grid]')).map(function(el) {
            return {
                id: el.getAttribute('data-vela-grid'),
                count: parseInt(el.getAttribute('data-vela-grid-count'), 10) || 0,
                label: (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 24)
            };
        });
    }

    /** Sizes offered as a list; "Custom…" reveals a box for anything else. */
    // Two columns is the row people rearrange; past that a split stops being
    // meaningful and the column count does the work.
    var LAYOUT_SPLITS = ['50/50', '60/40', '40/60', '70/30', '30/70', '33/67', '67/33'];
    var LAYOUT_GAPS = ['0px', '8px', '16px', '24px', '32px', '48px', '64px'];

    var DESIGN_PRESETS = {
        headingSize: { label: 'Heading size', values: ['28px', '32px', '40px', '48px', '56px', '64px', '72px'] },
        bodySize:    { label: 'Body text size', values: ['14px', '15px', '16px', '18px', '20px', '22px'] },
        padding:     { label: 'Space above &amp; below', values: ['0px', '24px', '40px', '64px', '80px', '120px', '160px'] },
        maxWidth:    { label: 'Content width', values: ['720px', '960px', '1140px', '1280px', '1440px', '100%'] }
    };

    function designLabel(text, extra) {
        return '<label class="mb-1 ' + (extra || '') + '" style="font-size:.75rem;color:#6c757d;">' + text + '</label>';
    }

    /**
     * A colour as a swatch and a text box together.
     *
     * Typing a hex code is fine for someone who has one; everyone else wants
     * to pick. Both write the same value, and the × puts it back to whatever
     * the section came with.
     */
    function designColourField(name, label, value, width) {
        var hex = /^#[0-9a-f]{6}$/i.test(value || '') ? value : '#ffffff';

        return '<div class="form-group ' + (width || 'col-md-6') + ' mb-2">' + designLabel(label) +
            '<div class="input-group input-group-sm vela-colour-group">' +
                '<div class="input-group-prepend"><span class="input-group-text p-0" style="overflow:hidden">' +
                    '<input type="color" class="vela-design-swatch" data-swatch-for="' + name + '" value="' + escHtml(hex) + '" ' +
                        'style="width:34px;height:29px;border:0;padding:0;background:none;cursor:pointer" ' +
                        'title="Pick a colour">' +
                '</span></div>' +
                '<input type="text" class="form-control vela-design" data-design="' + name + '" ' +
                    'value="' + escHtml(value || '') + '" placeholder="Keep the original">' +
                '<div class="input-group-append">' +
                    '<button class="btn btn-outline-secondary vela-design-clear" type="button" data-clear-for="' + name + '" ' +
                        'title="Keep the original">&times;</button>' +
                '</div>' +
            '</div></div>';
    }

    function designSizeField(name, value, width) {
        var preset = DESIGN_PRESETS[name];
        var known = preset.values.indexOf(value) > -1;
        var custom = !!value && !known;

        var options = ['<option value="">Keep the original</option>'];
        preset.values.forEach(function(v) {
            options.push('<option value="' + v + '"' + (value === v ? ' selected' : '') + '>' + v + '</option>');
        });
        options.push('<option value="__custom"' + (custom ? ' selected' : '') + '>Custom…</option>');

        return '<div class="form-group ' + (width || 'col-md-4') + ' mb-2">' + designLabel(preset.label) +
            '<select class="form-control form-control-sm vela-design" data-design="' + name + '">' + options.join('') + '</select>' +
            '<input type="text" class="form-control form-control-sm mt-1 vela-design-custom" data-design-custom="' + name + '" ' +
                'value="' + escHtml(custom ? value : '') + '" placeholder="e.g. 3rem"' + (custom ? '' : ' hidden') + '>' +
            '</div>';
    }

    /**
     * The id the selected part is styled by, creating one if it has none.
     *
     * Only when something is actually being set: an id handed out on every
     * click would put an attribute into the markup for each part merely looked
     * at, and each of those would land in the undo history as a change.
     */
    function selectedPartId(doc, assign) {
        if (_htmlSelected === null) return null;

        var el = nodeAtPath(doc, _htmlSelected);
        if (!el || !el.parentElement || el.hasAttribute('data-vela-block')) return null;

        var id = el.getAttribute('data-vela-field') || el.getAttribute('data-vela-part');
        if (id || !assign) return id;

        var next = 1;
        Array.prototype.forEach.call(doc.querySelectorAll('[data-vela-part]'), function(n) {
            var v = parseInt((n.getAttribute('data-vela-part') || '').slice(1), 10);
            if (v >= next) next = v + 1;
        });
        id = 'p' + next;
        el.setAttribute('data-vela-part', id);
        return id;
    }

    /** The swatch belonging to one part field. */
    function partSwatchSelector(name) {
        return name === 'color'
            ? '.vela-part-swatch:not([data-for])'
            : '.vela-part-swatch[data-for="' + name + '"]';
    }

    function partSelect(name, label, values, current, blank) {
        var options = ['<option value="">' + (blank || 'Keep the original') + '</option>'];
        values.forEach(function(v) {
            if (v === '') return;
            options.push('<option value="' + escHtml(v) + '"' +
                (String(current) === String(v) ? ' selected' : '') + '>' + escHtml(v) + '</option>');
        });
        return '<div class="form-group col-md-6 mb-2">' + designLabel(label) +
            '<select class="form-control form-control-sm vela-part-design" data-part-design="' + name + '">' +
            options.join('') + '</select></div>';
    }

    /**
     * The controls for whatever is selected in the preview.
     *
     * The design controls could only ever say something about the whole
     * section — every heading the same size, every paragraph the same colour —
     * so the one thing anyone wants next, a single paragraph made smaller or a
     * figure in the brand colour, had no way to be said at all.
     */
    function renderPartDesign(doc) {
        var id = selectedPartId(doc, false);
        var el = _htmlSelected === null ? null : nodeAtPath(doc, _htmlSelected);

        if (!el || el.hasAttribute('data-vela-block')) {
            return '<div class="alert alert-light border py-2 mb-3" style="font-size:.8rem;">' +
                'Click any part of the preview to style just that part — its size, colour, weight and spacing. ' +
                'The controls below apply to the whole section.' +
                '</div>';
        }

        var p = (id && _htmlPartStyles[id]) || {};
        var label = (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 32)
            || '<' + el.tagName.toLowerCase() + '>';
        var colour = /^#[0-9a-f]{6}$/i.test(p.color || '') ? p.color : '#111111';
        var background = /^#[0-9a-f]{6}$/i.test(p.background || '') ? p.background : '#ffffff';
        var set = Object.keys(p).length;

        return '<details class="mb-3" open id="vela-part-design" data-part-id="' + escHtml(id || '') + '">' +
            '<summary style="cursor:pointer;font-weight:500;">' +
                '<i class="fas fa-highlighter mr-1"></i> This part' +
                '<span class="text-muted ml-1" style="font-weight:400;font-size:.8rem;">— ' + escHtml(label) + '</span>' +
            '</summary>' +
            '<div class="form-row mt-2">' +
                '<div class="form-group col-md-6 mb-2">' + designLabel('Colour') +
                    '<div class="input-group input-group-sm">' +
                        '<div class="input-group-prepend"><span class="input-group-text p-0" style="overflow:hidden">' +
                            '<input type="color" class="vela-part-swatch" value="' + escHtml(colour) + '" ' +
                                'style="width:34px;height:29px;border:0;padding:0;background:none;cursor:pointer">' +
                        '</span></div>' +
                        '<input type="text" class="form-control vela-part-design" data-part-design="color" ' +
                            'value="' + escHtml(p.color || '') + '" placeholder="Keep the original">' +
                        '<div class="input-group-append">' +
                            '<button class="btn btn-outline-secondary vela-part-clear" type="button" ' +
                                'title="Keep the original">&times;</button>' +
                        '</div>' +
                    '</div></div>' +
                '<div class="form-group col-md-6 mb-2">' + designLabel('Background') +
                    '<div class="input-group input-group-sm vela-colour-group">' +
                        '<div class="input-group-prepend"><span class="input-group-text p-0" style="overflow:hidden">' +
                            '<input type="color" class="vela-part-swatch" data-for="background" value="' + escHtml(background) + '" ' +
                                'style="width:34px;height:29px;border:0;padding:0;background:none;cursor:pointer">' +
                        '</span></div>' +
                        '<input type="text" class="form-control vela-part-design" data-part-design="background" ' +
                            'value="' + escHtml(p.background || '') + '" placeholder="Keep the original">' +
                        '<div class="input-group-append">' +
                            '<button class="btn btn-outline-secondary vela-part-clear" type="button" data-for="background" ' +
                                'title="Keep the original">&times;</button>' +
                        '</div>' +
                    '</div></div>' +
                partSelect('padding', 'Inner spacing', PART_SPACES, p.padding) +
                partSelect('radius', 'Corners', PART_RADII, p.radius) +
                partSelect('size', 'Size', PART_SIZES, p.size) +
                partSelect('weight', 'Weight', PART_WEIGHTS, p.weight) +
                partSelect('style', 'Style', ['normal', 'italic'], p.style) +
                partSelect('align', 'Alignment', ['left', 'center', 'right'], p.align) +
                partSelect('lineHeight', 'Line height', PART_LINES, p.lineHeight) +
                partSelect('spaceBelow', 'Space below', PART_SPACES, p.spaceBelow) +
            '</div>' +
            (set ? '<button type="button" class="btn btn-link btn-sm px-0" id="vela-part-reset">' +
                'Clear this part\'s styling</button>' : '') +
            '</details>';
    }

    /**
     * Refresh only the controls for the selected part.
     *
     * Choosing a part must not redraw the preview: the preview is replaced
     * wholesale, and clicking a sentence to style it would throw away the caret
     * that same click had just placed in it.
     */
    /**
     * Redraw the panel around a new selection.
     *
     * What is in front of you decides what the panel shows — its wording, its
     * styling, the row it sits in — so a selection is not a detail of one
     * slot, it is the panel's subject.
     */
    function redrawPartPanel() {
        if (!_htmlDoc) return;

        // Whatever is typed but not yet written back would be lost in the
        // redraw; the document is the one place both sides agree on.
        applyDesign(applyImportedFields(_htmlDoc), collectDesign());

        var $root = $('#vela-design-root');
        if (!$root.length) return;
        $root.replaceWith(renderDesignPanel(_htmlDoc));
    }

    /**
     * The layout controls for a row, or for every row in the section.
     *
     * Elementor asks what you have selected and shows the controls for that;
     * a list of every row in the section, whatever you are working on, is the
     * thing that made this panel feel like a settings screen.
     */
    function layoutGroups(d, grids, onlyId) {
        return grids.filter(function(g) {
            if (onlyId) return g.id === onlyId;
            return g.count >= 2 && g.count <= 8 && g.label;
        }).slice(0, 6).map(function(g) {
            var columns = (d.grids || {})[g.id] || '';
            var layout = (d.layout || {})[g.id] || {};

            // Stacking is one column, so the arrangement and the column count
            // are the same choice and belong in the same list.
            var arrange = ['<option value="">Keep the original (' + g.count + ' across)</option>',
                '<option value="1"' + (String(columns) === '1' ? ' selected' : '') + '>Stacked, one under the other</option>'];
            for (var n = 2; n <= 6; n++) {
                arrange.push('<option value="' + n + '"' + (String(columns) === String(n) ? ' selected' : '') + '>' + n + ' across</option>');
            }

            var splits = ['<option value="">Even</option>'].concat(LAYOUT_SPLITS.map(function(v) {
                return '<option value="' + v + '"' + (layout.split === v ? ' selected' : '') + '>' + v.replace('/', ' / ') + '</option>';
            }));

            var gaps = ['<option value="">Keep the original</option>'].concat(LAYOUT_GAPS.map(function(v) {
                return '<option value="' + v + '"' + (layout.gap === v ? ' selected' : '') + '>' + v + '</option>';
            }));

            var aligns = [['', 'Keep the original'], ['start', 'Top'], ['center', 'Middle'], ['end', 'Bottom'], ['stretch', 'Same height']]
                .map(function(a) {
                    return '<option value="' + a[0] + '"' + (layout.align === a[0] ? ' selected' : '') + '>' + a[1] + '</option>';
                });

            return '<div class="col-12 mb-2 p-2" style="background:#f8f9fa;border-radius:6px;">' +
                '<div class="text-truncate mb-2" title="' + escHtml(g.label || g.id) + '" ' +
                    'style="font-size:.75rem;font-weight:600;color:#495057;">' +
                    '<i class="fas fa-table-columns mr-1"></i>' + escHtml(g.label || g.id) + '</div>' +
                '<div class="form-row">' +
                    '<div class="form-group col-md-6 mb-2">' + designLabel('Arrangement') +
                        '<select class="form-control form-control-sm vela-design" data-design-grid="' + escHtml(g.id) + '">' +
                        arrange.join('') + '</select>' +
                        // The same choice as a slider. Reading down a list to
                        // count cards across is the slow way to answer "how
                        // does four look"; dragging is the fast one, and the
                        // list stays for saying "keep the original".
                        '<div class="d-flex align-items-center mt-1" style="gap:8px;">' +
                            '<input type="range" class="form-range flex-grow-1 vela-grid-slider" ' +
                                'data-grid="' + escHtml(g.id) + '" min="1" max="6" step="1" ' +
                                'value="' + (parseInt(columns, 10) || g.count) + '">' +
                            '<span class="text-muted vela-grid-readout" style="font-size:.75rem;min-width:4.6em;text-align:right;">' +
                                (parseInt(columns, 10) || g.count) + ' across</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="form-group col-md-6 mb-2">' + designLabel('Widths') +
                        '<select class="form-control form-control-sm vela-layout" data-layout="split" data-grid="' + escHtml(g.id) + '"' +
                            (g.count === 2 ? '' : ' disabled title="Two columns only"') + '>' +
                        splits.join('') + '</select></div>' +
                    '<div class="form-group col-md-4 mb-2">' + designLabel('Gap') +
                        '<select class="form-control form-control-sm vela-layout" data-layout="gap" data-grid="' + escHtml(g.id) + '">' +
                        gaps.join('') + '</select></div>' +
                    '<div class="form-group col-md-4 mb-2">' + designLabel('Line up') +
                        '<select class="form-control form-control-sm vela-layout" data-layout="align" data-grid="' + escHtml(g.id) + '">' +
                        aligns.join('') + '</select></div>' +
                    '<div class="form-group col-md-4 mb-2 d-flex align-items-end">' +
                        '<div class="custom-control custom-checkbox">' +
                            '<input type="checkbox" class="custom-control-input vela-layout" data-layout="reverse" ' +
                                'data-grid="' + escHtml(g.id) + '" id="vela-rev-' + escHtml(g.id) + '"' + (layout.reverse ? ' checked' : '') + '>' +
                            '<label class="custom-control-label" for="vela-rev-' + escHtml(g.id) + '" style="font-size:.8rem;">Reverse order</label>' +
                        '</div>' +
                    '</div>' +
                '</div></div>';
        }).join('');
    }

    function renderDesignPanel(doc) {
        var wrapper = doc.querySelector('[data-vela-block]');
        if (!wrapper) return '';

        var d = readDesign(wrapper);
        var grids = gridElements(doc);

        var fonts = DESIGN_FONTS.map(function(f) {
            return '<option value="' + escHtml(f[0]) + '"' + (d.font === f[0] ? ' selected' : '') + '>' + escHtml(f[1]) + '</option>';
        }).join('');

        // Only the repeated rows a person would recognise. A comparison table
        // is dozens of "grids" of 18 and 36 cells whose only name would be
        // g7, g8, g9 — sixteen useless dropdowns burying the three that mean
        // something.
        var gridRows = layoutGroups(d, grids);

        var selected = _htmlSelected === null ? null : nodeAtPath(doc, _htmlSelected);
        if (selected && selected.hasAttribute('data-vela-block')) selected = null;

        // The row the selection sits in, so the layout tab talks about the
        // thing in front of you rather than every row in the section.
        var ownRow = null;
        for (var node = selected; node && !node.hasAttribute('data-vela-block'); node = node.parentElement) {
            if (node.hasAttribute('data-vela-grid')) { ownRow = node.getAttribute('data-vela-grid'); break; }
        }

        var sectionStyle = '<div class="form-row mt-2">' +
                designColourField('textColor', 'Text colour', d.textColor) +
                designColourField('background', 'Background', d.background) +
                '<div class="form-group col-md-6 mb-2">' + designLabel('Font') +
                    '<select class="form-control form-control-sm vela-design" data-design="font">' + fonts + '</select></div>' +
                designSizeField('headingSize', d.headingSize, 'col-md-6') +
                designSizeField('bodySize', d.bodySize, 'col-md-6') +
                designSizeField('padding', d.padding, 'col-md-6') +
                designSizeField('maxWidth', d.maxWidth, 'col-md-6') +
                '<div class="form-group col-md-6 mb-2">' + designLabel('Alignment') +
                    '<select class="form-control form-control-sm vela-design" data-design="align">' +
                        ['', 'left', 'center', 'right'].map(function(a) {
                            return '<option value="' + a + '"' + (d.align === a ? ' selected' : '') + '>' + (a === '' ? 'Keep the original' : a) + '</option>';
                        }).join('') +
                    '</select></div>' +
            '</div>';

        var fields = renderImportedFields(doc, selected);
        var emptyContent = '<div class="alert alert-light border py-2 mb-0"><small>' +
            (selected
                ? 'Nothing in this part is wording, a picture or a link. Style it under <strong>Style</strong>, ' +
                  'or pick something inside it in the preview.'
                : 'No editable wording was found in this section — its text sits inside markup this form cannot ' +
                  'take apart. Change it under "Edit the HTML directly" below.') +
            '</small></div>';

        var ownLayout = ownRow ? layoutGroups(d, grids, ownRow) : '';
        var restLayout = layoutGroups(d, grids);

        return '<div id="vela-design-root">' +
            renderBreadcrumb(doc, selected) +
            '<ul class="nav nav-tabs" id="vela-tabs">' +
                tabButton('content', 'Content', 'fa-pen') +
                tabButton('style', 'Style', 'fa-palette') +
                tabButton('layout', 'Layout', 'fa-table-columns') +
            '</ul>' +

            // Every tab is rendered and only hidden, never left out: the panel
            // is where a save reads its values from, and a control that is not
            // in the page is a choice quietly dropped.
            '<div class="vela-tab-body pt-3"' + (_htmlTab === 'content' ? '' : ' hidden') + ' data-tab="content">' +
                (selected
                    ? (fields || emptyContent + '<div id="vela-field-list"></div>')
                    // Nothing chosen yet: an invitation to point at something,
                    // with the whole section's wording folded away behind it.
                    // Opened flat, that list is a form to hunt through — 257
                    // rows on a pricing page — for a job the preview does in
                    // one click.
                    : '<div class="alert alert-light border py-2" style="font-size:.85rem;">' +
                        '<i class="fas fa-hand-pointer mr-1"></i> Click anything in the preview to work on it. ' +
                        'What you pick shows up here, with its own Style and Layout.' +
                      '</div>' +
                      (fields
                        ? '<details><summary style="cursor:pointer;font-size:.85rem;">' +
                            'All wording in this section (' + fieldElements(doc).length + ')</summary>' +
                            '<div class="mt-2">' + fields + '</div></details>'
                        : emptyContent + '<div id="vela-field-list"></div>')) +
            '</div>' +

            '<div class="vela-tab-body pt-3"' + (_htmlTab === 'style' ? '' : ' hidden') + ' data-tab="style">' +
                '<div id="vela-part-slot">' + renderPartDesign(doc) + '</div>' +
                '<details class="mb-3"' + (selected ? '' : ' open') + '>' +
                    '<summary style="cursor:pointer;font-weight:500;">' +
                        '<i class="fas fa-palette mr-1"></i> Whole section</summary>' +
                    sectionStyle +
                    renderHiddenParts(doc) +
                    '<button type="button" class="btn btn-link btn-sm px-0" id="vela-design-reset">Reset design to the original</button>' +
                '</details>' +
            '</div>' +

            '<div class="vela-tab-body pt-3"' + (_htmlTab === 'layout' ? '' : ' hidden') + ' data-tab="layout">' +
                (ownLayout || '') +
                (ownRow
                    ? '<details class="mb-2"><summary style="cursor:pointer;font-size:.85rem;">Other rows in this section</summary>' +
                        '<div class="form-row mt-2">' + restLayout + '</div></details>'
                    : (restLayout
                        ? '<div class="form-row">' + restLayout + '</div>'
                        : '<div class="alert alert-light border py-2 mb-0"><small>This section has no row of ' +
                          'columns to rearrange.</small></div>')) +
            '</div>' +
            '</div>';
    }

    function tabButton(name, label, icon) {
        return '<li class="nav-item">' +
            '<a class="nav-link' + (_htmlTab === name ? ' active' : '') + '" href="#" data-vela-tab="' + name + '" ' +
                'style="padding:.35rem .7rem;font-size:.85rem;">' +
                '<i class="fas ' + icon + ' mr-1"></i>' + label +
            '</a></li>';
    }

    /**
     * Where the selection sits, and a way back up.
     *
     * Reaching a card by aiming at the few pixels of padding around its
     * heading is the game this replaces: every ancestor is one click.
     */
    function renderBreadcrumb(doc, selected) {
        var crumbs = [{ path: null, label: 'Section' }];

        if (selected) {
            var chain = [];
            for (var node = selected; node && !node.hasAttribute('data-vela-block'); node = node.parentElement) {
                chain.unshift(node);
            }
            var path = [];
            chain.forEach(function(node) {
                var parent = node.parentElement;
                path.push(Array.prototype.indexOf.call(parent.children, node));
                crumbs.push({ path: path.join('/'), label: partName(node) });
            });
        }

        return '<nav class="mb-2 text-truncate" style="font-size:.75rem;color:#6c757d;">' +
            crumbs.map(function(c, i) {
                var last = i === crumbs.length - 1;
                var label = escHtml(c.label);
                return (last
                    ? '<span style="color:#212529;font-weight:600;">' + label + '</span>'
                    : '<a href="#" class="vela-crumb" data-path="' + escHtml(c.path === null ? '' : c.path) + '">' + label + '</a>');
            }).join('<span class="mx-1">›</span>') +
            '</nav>';
    }

    /** What to call a part in the breadcrumb. */
    function partName(el) {
        var tag = el.tagName.toLowerCase();
        if (/^h[1-6]$/.test(tag)) return 'Heading';
        if (tag === 'p') return 'Text';
        if (tag === 'img') return 'Image';
        if (tag === 'a') return 'Link';
        if (tag === 'button') return 'Button';
        if (tag === 'form') return 'Form';
        if (tag === 'ul' || tag === 'ol') return 'List';
        if (tag === 'li') return 'List item';
        if (el.hasAttribute('data-vela-grid')) return 'Row';
        if (el.hasAttribute('data-vela-card')) return 'Card';
        if (el.querySelector && el.querySelector('[data-vela-field]')) return 'Group';
        return tag === 'section' ? 'Section part' : 'Block';
    }

    /**
     * The "leave this out" controls.
     *
     * Copied sections carry things a site does not want — a field asking for a
     * company website, a promo strip, a whole column. Removing them from the
     * markup would be a one-way door, so they are only hidden: the part stays
     * in the block and comes back the moment it is shown again.
     */
    function renderHiddenParts(doc) {
        var rows = _htmlHidden.map(function(id) {
            var attribute = id.charAt(0) === 'f' ? 'data-vela-field' : 'data-vela-part';
            var el = doc.querySelector('[' + attribute + '="' + id + '"]');
            var label = el ? (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40) : '';
            if (!label && el) label = '<' + el.tagName.toLowerCase() + '>';
            return '<li class="d-flex align-items-center justify-content-between py-1" style="border-bottom:1px solid #f1f3f5;">' +
                '<span class="text-truncate" style="font-size:.8rem;">' + escHtml(label || id) + '</span>' +
                '<button type="button" class="btn btn-link btn-sm p-0 vela-part-show" data-target="' + escHtml(id) + '">Show again</button>' +
                '</li>';
        }).join('');

        // No mode to switch on: pointing at a part in the preview is what
        // offers its controls. All that is left here is what came out and the
        // way back in.
        return '<div class="mt-2 mb-2">' +
            '<div class="text-muted" style="font-size:.75rem;">' +
                'Click any wording in the preview to rewrite it, or a picture to swap it. ' +
                'Point at a part to drag it into a new place or leave it out — nothing is deleted, ' +
                'and whatever you take out is listed here.' +
            '</div>' +
            (_htmlMoveUnavailable ? '<div class="alert alert-warning py-1 mt-2 mb-0" style="font-size:.75rem;">' +
                '<i class="fas fa-exclamation-triangle mr-1"></i> Dragging needs the SortableJS file, which the preview ' +
                'could not load. Check that the admin page can reach it, then reopen this section.' +
                '</div>' : '') +
            (rows ? '<ul class="list-unstyled mt-2 mb-0">' + rows + '</ul>' : '') +
            '</div>';
    }

    /**
     * Mark the element a click landed on so it can be referred to later.
     *
     * The preview is a copy, so a click there comes back as a position path
     * ("3/1/0") into the wrapper. The same path is walked in the block's own
     * document and the element found gets a part id, which is what the hidden
     * list and the generated CSS use from then on.
     */
    /** Walk a position path — "3/1/0" — down from the section wrapper. */
    function nodeAtPath(doc, path) {
        var node = doc.querySelector('[data-vela-block]');
        if (!node) return null;

        var steps = String(path).split('/').filter(function(step) { return step !== ''; });
        for (var i = 0; i < steps.length; i++) {
            node = node.children[parseInt(steps[i], 10)];
            if (!node) return null;
        }
        return node;
    }

    function markPartAtPath(doc, path) {
        var node = nodeAtPath(doc, path);
        if (!node) return null;

        // Never the wrapper itself: hiding that hides the whole section, which
        // is what deleting the block is for.
        if (!node.parentElement || node.hasAttribute('data-vela-block')) return null;

        var field = node.getAttribute('data-vela-field');
        if (field) return field;

        var existing = node.getAttribute('data-vela-part');
        if (existing) return existing;

        var next = 1;
        Array.prototype.forEach.call(doc.querySelectorAll('[data-vela-part]'), function(el) {
            var n = parseInt((el.getAttribute('data-vela-part') || '').slice(1), 10);
            if (n >= next) next = n + 1;
        });
        var id = 'p' + next;
        node.setAttribute('data-vela-part', id);

        return id;
    }

    /** The next free number for one of the importer's id attributes. */
    function nextImportedId(doc, attribute, prefix) {
        var next = 1;
        Array.prototype.forEach.call(doc.querySelectorAll('[' + attribute + ']'), function(el) {
            var n = parseInt((el.getAttribute(attribute) || '').slice(prefix.length), 10);
            if (n >= next) next = n + 1;
        });
        return next;
    }

    /**
     * Give a copied part ids of its own.
     *
     * The ids are what the form rows, the design CSS and the hidden list are
     * keyed by, so a copy carrying the original's would not be a second card:
     * editing either row would write to both, hiding one would hide both, and
     * the form would show two rows fighting over the same wording.
     */
    function freshenImportedIds(doc, node) {
        [['data-vela-field', 'f'], ['data-vela-part', 'p'], ['data-vela-grid', 'g']].forEach(function(pair) {
            var attribute = pair[0], prefix = pair[1];
            var next = nextImportedId(doc, attribute, prefix);
            var targets = node.hasAttribute(attribute) ? [node] : [];
            targets = targets.concat(Array.prototype.slice.call(node.querySelectorAll('[' + attribute + ']')));
            targets.forEach(function(el) { el.setAttribute(attribute, prefix + (next++)); });
        });
    }

    /**
     * Put a second copy of a part straight after it.
     *
     * A section copied from another site is rarely missing an empty box — what
     * it is missing is a fourth card like the three already there. A copy
     * arrives with the original's classes and proportions, so it looks right
     * before a word of it has been changed.
     *
     * Returns the new part's path, so it can be the one left selected.
     */
    function duplicateImportedPart(path) {
        var node = nodeAtPath(_htmlDoc, path);
        if (!node || !node.parentElement || node.hasAttribute('data-vela-block')) return null;

        var copy = node.cloneNode(true);
        // Renumbered before it joins the document, so the scan for the highest
        // id in use cannot count the copy's borrowed ones.
        freshenImportedIds(_htmlDoc, copy);
        node.parentElement.insertBefore(copy, node.nextSibling);

        // It lands directly after the original, so only the last step of the
        // path changes and nothing before it moves.
        var steps = String(path).split('/');
        steps[steps.length - 1] = String(parseInt(steps[steps.length - 1], 10) + 1);
        return steps.join('/');
    }

    /**
     * Move one child of a container to another position in it.
     *
     * The ids the form, the design CSS and the hidden list are keyed by sit on
     * the elements themselves, so a moved element takes its wording, its
     * picture and its styling along and none of those lists need renumbering.
     */
    function moveImportedPart(containerPath, from, to) {
        var container = nodeAtPath(_htmlDoc, containerPath);
        if (!container) return false;

        var kids = container.children;
        if (from === to || from < 0 || to < 0 || from >= kids.length || to >= kids.length) return false;

        // Taking the element out shifts everything after it up by one, so a
        // move to the right lands one place short without this.
        var moved = kids[from];
        container.insertBefore(moved, kids[to > from ? to + 1 : to] || null);

        return true;
    }

    function collectDesign() {
        var design = { grids: {}, hidden: _htmlHidden.slice(), parts: _htmlPartStyles };
        $('.vela-design').each(function() {
            var $el = $(this);
            var value = $el.val();

            if ($el.data('design-grid')) {
                if (value) design.grids[$el.data('design-grid')] = value;
                return;
            }

            var name = $el.data('design');
            // "Custom…" hands over to the box beside it.
            if (value === '__custom') {
                value = $('[data-design-custom="' + name + '"]').val();
            }
            if (value) design[name] = value;
        });
        $('.vela-layout').each(function() {
            var $el = $(this);
            var id = $el.data('grid');
            var key = $el.data('layout');
            var value = $el.is(':checkbox') ? $el.is(':checked') : $el.val();
            if (!id || !key || !value) return;
            design.layout = design.layout || {};
            design.layout[id] = design.layout[id] || {};
            design.layout[id][key] = value;
        });

        if (!Object.keys(design.grids).length) delete design.grids;
        if (!Object.keys(design.parts).length) delete design.parts;
        return design;
    }

    function toggleHiddenPart(id) {
        if (!id) return;
        var at = _htmlHidden.indexOf(id);
        if (at > -1) _htmlHidden.splice(at, 1);
        else _htmlHidden.push(id);
        redrawImportedEditor();
    }

    /**
     * Redraw the form and the design panel from the current document.
     *
     * Hiding a part changes both lists, and rebuilding them by hand from
     * three places is how they drift apart. The values already typed are
     * written into the document first so nothing is lost in the redraw.
     */
    function redrawImportedEditor(fromDoc) {
        if (!_htmlDoc) return;

        // `fromDoc` means the document is already the truth and the form is
        // stale — the case after an undo.
        if (!fromDoc) applyDesign(applyImportedFields(_htmlDoc), collectDesign());

        var $panel = $('#block-edit-content');
        var scroll = $panel.scrollTop();
        // The panel holds the wording list inside its Content tab now, so one
        // redraw covers both; replacing the list separately afterwards put a
        // second copy of it on the page.
        $panel.find('#vela-design-root').replaceWith(renderDesignPanel(_htmlDoc));
        $panel.scrollTop(scroll);

        refreshImportedPreview();
    }

    PageEditor.registerBlockType('html', {
        icon: 'fa-code',
        label: 'Custom HTML',
        defaults: { content: { html: '' }, settings: {} },
        renderPreview: function(block) {
            var htmlContent = (block.content && block.content.html) ? block.content.html : '';

            // An imported section is styled by the page's own stylesheet, and
            // dropping it straight into the admin showed it stripped of every
            // rule — a wall of bullet points nobody could recognise as their
            // page. Rendered in a frame with that stylesheet, it looks like
            // what it is.
            if (htmlContent.indexOf('data-vela-field') > -1 || /class="[^"]*vela-import-/.test(htmlContent)) {
                var doc = '<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;overflow-x:hidden}' +
                    pageCustomCss() + '</style></head><body>' + htmlContent + '</body></html>';
                return '<div style="border:1px solid #e9ecef;border-radius:4px;overflow:hidden;background:#fff;position:relative;">' +
                    '<iframe class="vela-import-thumb" style="width:100%;height:260px;border:0;display:block;" srcdoc="' + escHtml(doc) + '"></iframe>' +
                    '<div style="position:absolute;inset:0;"></div>' +
                    '</div>';
            }

            return '<div style="border:1px solid #e9ecef;border-radius:4px;padding:8px;background:#fafafa;">' + htmlContent + '</div>';
        },
        renderEditor: function(block) {
            var html = block.content && block.content.html ? block.content.html : '';
            _htmlDoc = upgradeImportedBlock(parseBlockHtml(html));
            _htmlMoveUnavailable = false;
            _htmlSelected = null;
            _htmlPartStyles = {};
            _blockHistory = newHistory();
            var opened = readDesign(_htmlDoc.querySelector('[data-vela-block]'));
            _htmlHidden = (opened.hidden || []).slice();
            _htmlPartStyles = JSON.parse(JSON.stringify(opened.parts || {}));
            var fields = renderImportedFields(_htmlDoc);
            var imported = !!_htmlDoc.querySelector('[data-vela-block]');
            _htmlHasFields = !!fields || imported;

            // A plain hand-written HTML block: the textarea is all there is.
            if (!fields && !imported) {
                return '<div class="form-group"><label>Custom HTML</label>' +
                    '<div class="alert alert-warning py-1 mb-2"><small><i class="fas fa-exclamation-triangle"></i> This content will be rendered as-is. Use with caution.</small></div>' +
                    '<textarea class="form-control" id="html-content" rows="10" style="font-family:monospace;">' + escHtml(html) + '</textarea></div>';
            }

            // An imported section always gets the preview and the design
            // controls, even when nothing in it was recognised as editable
            // wording — otherwise a section like a bare headline dropped the
            // user back to a textarea of raw markup with no way to see it.
            return '<div class="mb-2 text-muted" style="font-size:.85rem;">' +
                    '<i class="fas fa-wand-magic-sparkles mr-1"></i> Imported section — work on it straight in the preview: ' +
                    'click wording to rewrite it, click a picture to swap it, and point at any part to drag it ' +
                    'somewhere else or leave it out. The design is below; anything left blank keeps the original.' +
                '</div>' +
                '<iframe id="vela-html-preview" style="width:100%;height:300px;border:1px solid #e9ecef;border-radius:4px;background:#fff;margin-bottom:12px;"></iframe>' +
                renderDesignPanel(_htmlDoc) +
                '<details class="mt-3"><summary style="cursor:pointer;font-weight:500;font-size:.9em;">' +
                    '<i class="fas fa-code mr-1"></i> Edit the HTML directly</summary>' +
                    '<textarea class="form-control mt-2" id="html-content" rows="10" style="font-family:monospace;font-size:.8rem;">' + escHtml(html) + '</textarea>' +
                '</details>';
        },
        initEditor: function(block) {
            if (!$('#vela-html-preview').length) return;

            // Bound fresh each time the dialog opens. Without clearing, the
            // handlers stack up: the second time a section was opened, one
            // click on "leave this out" ran the toggle twice and the part
            // stayed exactly as it was.
            $('#block-edit-content').off('.velaImported');
            $('#vela-field-list').off('.velaImported');
            $(window).off('.velaImported');

            refreshImportedPreview();

            // Delegated from the panel, not from the list. Every redraw
            // replaces the list wholesale, which threw away any handler bound
            // to it: after hiding a part — or copying one — typing in these
            // rows quietly stopped reaching the section.
            $('#block-edit-content').on('input.velaImported',
                '#vela-field-list .vela-field-text, #vela-field-list .vela-field-href, ' +
                '#vela-field-list .vela-field-src, #vela-field-list .vela-field-alt, ' +
                '#vela-field-list .vela-field-placeholder', function() {
                if ($(this).hasClass('vela-field-src')) {
                    $(this).closest('[data-field]').find('.vela-field-thumb').attr('src', $(this).val());
                }
                scheduleImportedPreview();
            });

            $('#block-edit-content').on('input.velaImported', '#vela-field-list .vela-field-width', function() {
                $(this).closest('[data-field]').find('.vela-field-width-readout').text($(this).val() + '%');
                scheduleImportedPreview();
            });

            $('#block-edit-content').on('change.velaImported', '#vela-field-list .vela-field-newtab', function() {
                scheduleImportedPreview();
            });

            $('#block-edit-content').on('click.velaImported', '[data-vela-tab]', function(e) {
                e.preventDefault();
                _htmlTab = $(this).data('vela-tab');
                $('#vela-tabs .nav-link').removeClass('active');
                $(this).addClass('active');
                $('.vela-tab-body').each(function() {
                    $(this).attr('hidden', $(this).data('tab') === _htmlTab ? null : 'hidden');
                });
            });

            $('#block-edit-content').on('click.velaImported', '.vela-crumb', function(e) {
                e.preventDefault();
                var path = $(this).data('path');
                _htmlSelected = (path === '' || path === undefined) ? null : String(path);
                redrawPartPanel();
                // The preview holds the same selection, so it has to hear about
                // one made from the breadcrumb.
                refreshImportedPreview();
            });

            $('#block-edit-content').on('change.velaImported', '.vela-layout', function() { scheduleImportedPreview(); });

            // The slider drives the select rather than the design map, so the
            // two can never disagree about how many columns the row has.
            $('#block-edit-content').on('input.velaImported', '.vela-grid-slider', function() {
                var $slider = $(this);
                var value = String(parseInt($slider.val(), 10));
                $slider.closest('.form-group').find('.vela-grid-readout')
                    .text(value + (value === '1' ? ' — stacked' : ' across'));
                $slider.closest('.form-group')
                    .find('.vela-design[data-design-grid="' + $slider.data('grid') + '"]')
                    .val(value).trigger('change');
            });

            $('#block-edit-content').on('change.velaImported input.velaImported', '.vela-design, .vela-design-custom', function() {
                var $el = $(this);
                var name = $el.data('design');

                // Show the free-text box only while "Custom…" is chosen, and
                // keep the swatch in step with a hex typed by hand.
                if ($el.is('select') && name) {
                    var $custom = $('[data-design-custom="' + name + '"]');
                    if ($el.val() === '__custom') { $custom.removeAttr('hidden').focus(); }
                    else { $custom.attr('hidden', 'hidden').val(''); }
                }
                if (name && /^#[0-9a-fA-F]{6}$/.test($el.val() || '')) {
                    $('[data-swatch-for="' + name + '"]').val($el.val());
                }

                scheduleImportedPreview();
            });

            $('#block-edit-content').on('input.velaImported change.velaImported', '.vela-design-swatch', function() {
                $('[data-design="' + $(this).data('swatch-for') + '"]').val($(this).val());
                scheduleImportedPreview();
            });

            $('#block-edit-content').on('click.velaImported', '.vela-field-visibility', function() {
                toggleHiddenPart($(this).data('target'));
            });

            $('#block-edit-content').on('click.velaImported', '.vela-part-show', function() {
                toggleHiddenPart($(this).data('target'));
            });

            // Styling one part. The id is minted here rather than on selection,
            // so merely looking at a part leaves the markup alone.
            $('#block-edit-content').on('change.velaImported input.velaImported', '.vela-part-design', function() {
                var id = selectedPartId(_htmlDoc, true);
                if (!id) return;

                var values = _htmlPartStyles[id] || {};
                $('#vela-part-design').find('.vela-part-design').each(function() {
                    var name = $(this).data('part-design');
                    var value = ($(this).val() || '').trim();
                    if (value) values[name] = value; else delete values[name];
                });

                if (Object.keys(values).length) _htmlPartStyles[id] = values;
                else delete _htmlPartStyles[id];

                // Each swatch follows its own field: there are two of them now,
                // and keeping them both on the text colour meant the
                // background picker showed the wrong colour the moment a hex
                // was typed.
                ['color', 'background'].forEach(function(name) {
                    if (!/^#[0-9a-fA-F]{6}$/.test(values[name] || '')) return;
                    $('#vela-part-design').find(partSwatchSelector(name)).val(values[name]);
                });
                $('#vela-part-design').attr('data-part-id', id);
                scheduleImportedPreview();
            });

            $('#block-edit-content').on('input.velaImported change.velaImported', '.vela-part-swatch', function() {
                var name = $(this).data('for') || 'color';
                $('#vela-part-design').find('[data-part-design="' + name + '"]').val($(this).val()).trigger('change');
            });

            $('#block-edit-content').on('click.velaImported', '.vela-part-clear', function() {
                var name = $(this).data('for') || 'color';
                $('#vela-part-design').find('[data-part-design="' + name + '"]').val('').trigger('change');
            });

            $('#block-edit-content').on('click.velaImported', '#vela-part-reset', function() {
                var id = selectedPartId(_htmlDoc, false);
                if (id) delete _htmlPartStyles[id];
                redrawImportedEditor();
            });

            $(window).on('message.velaImported', function(event) {
                var data = event.originalEvent && event.originalEvent.data;
                if (!data || !_htmlDoc) return;

                if (data.velaUndo) {
                    if (data.velaUndo.redo) runRedo(); else runUndo();
                    return;
                }

                // The shortcut belongs to whatever is wrapped around the editor,
                // which knows how the page is saved; this only carries it back
                // out of the frame.
                if (data.velaSave) {
                    $(document).trigger('vela:request-save');
                    return;
                }

                // Remembered out here, because the preview it was chosen in is
                // thrown away and rebuilt on the next change.
                if ('velaSelect' in data) {
                    _htmlSelected = data.velaSelect;
                    redrawPartPanel();
                    return;
                }

                // Everything past this point changes the section — wording,
                // images, parts being duplicated or moved — and none of it
                // passes through a form field, so it would not otherwise count
                // as work worth warning about on the way out.
                if (!('velaMoveUnavailable' in data)) {
                    _blockEditTouched = true;
                }

                // Carries nothing but that fact.
                if (data.velaTouched) return;

                // Wording typed into the preview. The form row is the one place
                // the block is read back from, so the edit lands there and the
                // saved markup is rewritten — but the preview is left alone, or
                // the caret would jump to the top of the section mid-sentence.
                if (data.velaText && data.velaText.field) {
                    var $richRow = $('#vela-field-list [data-field="' + data.velaText.field + '"]');
                    var $text = $richRow.find('.vela-field-text');
                    if ($text.length) {
                        var clean = sanitizeRichHtml(data.velaText.html);
                        if ($richRow.attr('data-html') !== clean) {
                            $richRow.attr('data-html', clean);
                            $text.val(plainFromHtml(clean, !!data.velaText.multiline));
                            writeImportedHtml();
                        }
                    }
                    return;
                }

                // A picture is the one thing that cannot be typed, so clicking
                // it opens the library instead. This one does redraw: the new
                // picture has to appear, and nothing is being typed.
                if (data.velaImage && data.velaImage.field) {
                    var $row = $('#vela-field-list [data-field="' + data.velaImage.field + '"]');
                    if ($row.length) {
                        openMediaBrowser(function(media) {
                            $row.find('.vela-field-src').val(media.url);
                            $row.find('.vela-field-thumb').attr('src', media.url);
                            if (media.alt && !$row.find('.vela-field-alt').val()) {
                                $row.find('.vela-field-alt').val(media.alt);
                            }
                            refreshImportedPreview();
                        });
                    }
                    return;
                }

                if (typeof data.velaPick === 'string') {
                    var id = markPartAtPath(_htmlDoc, data.velaPick);
                    if (id) toggleHiddenPart(id);
                    return;
                }

                if (data.velaMoveUnavailable && !_htmlMoveUnavailable) {
                    _htmlMoveUnavailable = true;
                    redrawImportedEditor();
                    return;
                }

                // A move can change which grids the design panel offers, so the
                // whole editor is redrawn rather than only the preview.
                // The copy becomes the selected one: it is what the next thing
                // you do — rewriting its wording — is meant to land on.
                if (typeof data.velaDuplicate === 'string') {
                    var made = duplicateImportedPart(data.velaDuplicate);
                    if (made !== null) {
                        _htmlSelected = made;
                        redrawImportedEditor();
                    }
                    return;
                }

                if (data.velaMove) {
                    if (moveImportedPart(data.velaMove.container, data.velaMove.from, data.velaMove.to)) {
                        // Follow the part to where it landed, so the redraw
                        // hands the choice back on the moved element rather
                        // than on whatever now sits at the old position.
                        var container = String(data.velaMove.container || '');
                        _htmlSelected = (container ? container + '/' : '') + data.velaMove.to;
                        redrawImportedEditor();
                    }
                }
            });

            $('#block-edit-content').on('click.velaImported', '.vela-design-clear', function() {
                $('[data-design="' + $(this).data('clear-for') + '"]').val('');
                refreshImportedPreview();
            });

            $('#block-edit-content').on('click.velaImported', '#vela-design-reset', function() {
                $('.vela-design').val('');
                $('.vela-design-custom').val('').attr('hidden', 'hidden');
                $('.vela-layout').not(':checkbox').val('');
                $('.vela-layout:checkbox').prop('checked', false);
                _htmlHidden = [];
                _htmlPartStyles = {};
                redrawImportedEditor();
            });

            $('#block-edit-content').on('click.velaImported', '#vela-field-list .vela-field-browse', function() {
                var $row = $(this).closest('[data-field]');
                openMediaBrowser(function(media) {
                    $row.find('.vela-field-src').val(media.url);
                    $row.find('.vela-field-thumb').attr('src', media.url);
                    if (media.alt && !$row.find('.vela-field-alt').val()) $row.find('.vela-field-alt').val(media.alt);
                    refreshImportedPreview();
                });
            });

            // Editing the markup by hand rebuilds the form from it, so the two
            // never drift apart.
            $('#html-content').on('change.velaImported', function() {
                _htmlDoc = parseBlockHtml($(this).val());
                $('#vela-field-list').replaceWith(renderImportedFields(_htmlDoc) || '<div id="vela-field-list"></div>');
                refreshImportedPreview();
            });
        },
        collectData: function(block) {
            // A plain HTML block is still edited in the textarea; only a block
            // with importer marks is rebuilt from the form.
            var html = (_htmlHasFields && _htmlDoc)
                ? serializeBlockHtml(applyDesign(applyImportedFields(_htmlDoc), collectDesign()))
                : $('#html-content').val();

            return {
                content: { html: html },
                settings: block.settings
            };
        }
    });

    PageEditor.registerBlockType('accordion', {
        icon: 'fa-list-ul',
        label: 'Accordion Q&A',
        defaults: { content: { items: [] }, settings: { first_open: true } },
        renderPreview: function(block) {
            var items = (block.content && block.content.items) ? block.content.items : [];
            var settings = block.content && block.content.settings ? block.content.settings : {};
            if (!items.length) return '<em>No accordion items</em>';
            var accHtml = '';
            items.forEach(function(item, idx) {
                var isOpen = (idx === 0 && settings.first_open);
                var bodyStyle = isOpen
                    ? 'max-height:2000px;padding:10px 15px;overflow:hidden;transition:max-height 0.3s ease,padding 0.3s ease;'
                    : 'max-height:0;overflow:hidden;transition:max-height 0.3s ease,padding 0.3s ease;padding:0 15px;';
                var chevronStyle = isOpen ? 'transform:rotate(180deg);transition:transform 0.3s;' : 'transition:transform 0.3s;';
                var itemClass = 'admin-acc-item' + (isOpen ? ' open' : '');
                accHtml += '<div class="' + itemClass + '">' +
                    '<div class="admin-acc-header" style="padding:10px 15px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:2px;font-weight:500;font-size:0.9em;">' +
                        '<span>' + escHtml(item.title || '') + '</span>' +
                        '<svg class="admin-acc-chevron" style="' + chevronStyle + '" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>' +
                    '</div>' +
                    '<div class="admin-acc-body" style="' + bodyStyle + '">' + (item.content || '') + '</div>' +
                '</div>';
            });
            return accHtml;
        },
        renderEditor: function(block) {
            var items = block.content && block.content.items ? block.content.items : [];
            var firstOpen = block.settings && typeof block.settings.first_open !== 'undefined' ? block.settings.first_open : true;
            var itemsHtml = '';
            items.forEach(function(item, i) {
                itemsHtml += buildAccordionItemRow(item, i);
            });
            return '<div id="accordion-items-list">' + itemsHtml + '</div>' +
                '<button type="button" class="btn btn-sm btn-success mt-2" id="add-accordion-item">+ Add Item</button>' +
                '<div class="form-check mt-3">' +
                    '<input type="checkbox" class="form-check-input" id="accordion-first-open"' + (firstOpen ? ' checked' : '') + '>' +
                    '<label class="form-check-label" for="accordion-first-open">First item expanded by default</label>' +
                '</div>';
        },
        initEditor: function(block) {
            initAccordionEditor(block);
        },
        collectData: function(block) {
            var items = [];
            $('#accordion-items-list .accordion-item-row').each(function() {
                items.push({
                    title: $(this).find('.acc-title').val(),
                    body: $(this).find('.acc-body').val()
                });
            });
            return {
                content: { items: items },
                settings: { first_open: $('#accordion-first-open').is(':checked') }
            };
        }
    });

    PageEditor.registerBlockType('contact_form', {
        icon: 'fa-envelope',
        label: 'Contact Form',
        defaults: {
            content: {},
            settings: {
                fields: {
                    name: { enabled: true, required: true },
                    email: { enabled: true, required: true },
                    phone: { enabled: true, required: false },
                    subject: { enabled: true, required: false },
                    message: { enabled: true, required: true }
                },
                submit_label: 'Send Message',
                success_message: 'Thank you for your message!'
            }
        },
        renderPreview: function(block) {
            var fields = block.content && block.content.fields ? block.content.fields : {};
            var fieldNames = ['name', 'email', 'phone', 'subject', 'message'];
            var formHtml = '<div style="font-size:0.85em;">';
            fieldNames.forEach(function(f) {
                if (fields[f] === false) return;
                formHtml += '<div style="margin-bottom:6px;"><label style="display:block;font-weight:500;">' + escHtml(f.charAt(0).toUpperCase() + f.slice(1)) + '</label>';
                if (f === 'message') {
                    formHtml += '<textarea disabled style="width:100%;border:1px solid #dee2e6;border-radius:3px;padding:4px 6px;background:#f8f9fa;resize:none;" rows="2"></textarea>';
                } else {
                    formHtml += '<input type="text" disabled style="width:100%;border:1px solid #dee2e6;border-radius:3px;padding:4px 6px;background:#f8f9fa;">';
                }
                formHtml += '</div>';
            });
            formHtml += '<button disabled style="background:#007bff;color:#fff;border:none;padding:6px 16px;border-radius:3px;opacity:0.7;">Submit</button></div>';
            return formHtml;
        },
        renderEditor: function(block) {
            var settings = block.settings || {};
            var fields = settings.fields || {
                name: { enabled: true, required: true },
                email: { enabled: true, required: true },
                phone: { enabled: true, required: false },
                subject: { enabled: true, required: false },
                message: { enabled: true, required: true }
            };
            var submitLabel = settings.submit_label || 'Send Message';
            var successMessage = settings.success_message || 'Thank you for your message!';
            var fieldNames = ['name', 'email', 'phone', 'subject', 'message'];
            var tableRows = fieldNames.map(function(f) {
                var fd = fields[f] || { enabled: false, required: false };
                return '<tr><td>' + f.charAt(0).toUpperCase() + f.slice(1) + '</td>' +
                    '<td><input type="checkbox" class="cf-enabled" data-field="' + f + '"' + (fd.enabled ? ' checked' : '') + '></td>' +
                    '<td><input type="checkbox" class="cf-required" data-field="' + f + '"' + (fd.required ? ' checked' : '') + '></td></tr>';
            }).join('');
            return '<table class="table table-sm"><thead><tr><th>Field</th><th>Enabled</th><th>Required</th></tr></thead><tbody>' + tableRows + '</tbody></table>' +
                '<div class="form-group"><label>Submit Button Label</label><input type="text" class="form-control" id="cf-submit-label" value="' + escHtml(submitLabel) + '"></div>' +
                '<div class="form-group"><label>Success Message</label><input type="text" class="form-control" id="cf-success-msg" value="' + escHtml(successMessage) + '"></div>';
        },
        initEditor: function(block) {},
        collectData: function(block) {
            var fieldNames = ['name', 'email', 'phone', 'subject', 'message'];
            var fields = {};
            fieldNames.forEach(function(f) {
                fields[f] = {
                    enabled: $('.cf-enabled[data-field="' + f + '"]').is(':checked'),
                    required: $('.cf-required[data-field="' + f + '"]').is(':checked')
                };
            });
            return {
                content: block.content,
                settings: {
                    fields: fields,
                    submit_label: $('#cf-submit-label').val(),
                    success_message: $('#cf-success-msg').val()
                }
            };
        }
    });

    PageEditor.registerBlockType('carousel', {
        icon: 'fa-images',
        label: 'Carousel Slider',
        defaults: { content: { slides: [] }, settings: { autoplay: true, interval: 5000, show_arrows: true, show_dots: true } },
        renderPreview: function(block) {
            var slides = block.content && block.content.slides ? block.content.slides : [];
            if (!slides.length) return '<em class="text-muted">No slides added</em>';
            var stripHtml = '<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">';
            slides.forEach(function(slide) {
                if (slide.image_url) {
                    stripHtml += '<img src="' + escHtml(slide.image_url) + '" style="height:80px;border-radius:4px;object-fit:cover;">';
                } else {
                    stripHtml += '<div style="height:80px;width:80px;background:#e9ecef;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:0.75em;color:#6c757d;">No image</div>';
                }
            });
            stripHtml += '</div><div style="font-size:0.85em;color:#555;margin-top:4px;">' + slides.length + ' slide' + (slides.length !== 1 ? 's' : '') + '</div>';
            return stripHtml;
        },
        renderEditor: function(block) {
            var slides = block.content && block.content.slides ? block.content.slides : [];
            var settings = block.settings || {};
            var autoplay = typeof settings.autoplay !== 'undefined' ? settings.autoplay : true;
            var interval = typeof settings.interval !== 'undefined' ? settings.interval : 5000;
            var showArrows = typeof settings.show_arrows !== 'undefined' ? settings.show_arrows : true;
            var showDots = typeof settings.show_dots !== 'undefined' ? settings.show_dots : true;
            var slidesHtml = '';
            slides.forEach(function(slide, i) {
                slidesHtml += buildCarouselSlideRow(slide, i);
            });
            return '<div id="carousel-slides-list">' + slidesHtml + '</div>' +
                '<div class="mt-2" style="display:flex;gap:8px;">' +
                '<button type="button" class="btn btn-sm btn-success" id="add-carousel-slide"><i class="fas fa-plus mr-1"></i> Add Slide</button>' +
                '<button type="button" class="btn btn-sm btn-outline-info" id="bulk-add-carousel"><i class="fas fa-images mr-1"></i> Bulk Add from Library</button>' +
                '</div>' +
                '<hr>' +
                '<div class="form-check">' +
                    '<input type="checkbox" class="form-check-input" id="carousel-autoplay"' + (autoplay ? ' checked' : '') + '>' +
                    '<label class="form-check-label" for="carousel-autoplay">Autoplay</label>' +
                '</div>' +
                '<div class="form-group mt-2"><label>Interval (ms)</label>' +
                    '<input type="number" class="form-control" id="carousel-interval" value="' + escHtml(String(interval)) + '" min="500" step="500">' +
                '</div>' +
                '<div class="form-check">' +
                    '<input type="checkbox" class="form-check-input" id="carousel-arrows"' + (showArrows ? ' checked' : '') + '>' +
                    '<label class="form-check-label" for="carousel-arrows">Show Arrows</label>' +
                '</div>' +
                '<div class="form-check mt-1">' +
                    '<input type="checkbox" class="form-check-input" id="carousel-dots"' + (showDots ? ' checked' : '') + '>' +
                    '<label class="form-check-label" for="carousel-dots">Show Dots</label>' +
                '</div>';
        },
        initEditor: function(block) {
            initCarouselEditor(block);
        },
        collectData: function(block) {
            var slides = [];
            $('#carousel-slides-list .carousel-slide-row').each(function() {
                slides.push({
                    image_url: $(this).find('.slide-image').val(),
                    caption: $(this).find('.slide-caption').val(),
                    link: $(this).find('.slide-link').val()
                });
            });
            return {
                content: { slides: slides },
                settings: {
                    autoplay: $('#carousel-autoplay').is(':checked'),
                    interval: parseInt($('#carousel-interval').val()) || 5000,
                    show_arrows: $('#carousel-arrows').is(':checked'),
                    show_dots: $('#carousel-dots').is(':checked')
                }
            };
        }
    });

    PageEditor.registerBlockType('gallery', {
        icon: 'fa-th',
        label: 'Image Gallery',
        defaults: { content: { images: [] }, settings: { columns: 3, gap: 10, lightbox: true } },
        renderPreview: function(block) {
            var images = block.content && block.content.images ? block.content.images : [];
            if (!images.length) return '<em class="text-muted">No images added</em>';
            var cols = block.settings && block.settings.columns ? block.settings.columns : 3;
            var gridHtml = '<div style="display:grid;grid-template-columns:repeat(' + cols + ',1fr);gap:4px;">';
            images.forEach(function(img) {
                if (img.url) {
                    gridHtml += '<img src="' + escHtml(img.url) + '" style="height:60px;object-fit:cover;border-radius:3px;">';
                } else {
                    gridHtml += '<div style="height:60px;background:#e9ecef;border-radius:3px;display:flex;align-items:center;justify-content:center;font-size:0.7em;color:#6c757d;">No image</div>';
                }
            });
            gridHtml += '</div><div style="font-size:0.85em;color:#555;margin-top:4px;">' + images.length + ' image' + (images.length !== 1 ? 's' : '') + '</div>';
            return gridHtml;
        },
        renderEditor: function(block) {
            var images = block.content && block.content.images ? block.content.images : [];
            var settings = block.settings || {};
            var columns = typeof settings.columns !== 'undefined' ? settings.columns : 3;
            var gap = typeof settings.gap !== 'undefined' ? settings.gap : 10;
            var lightbox = typeof settings.lightbox !== 'undefined' ? settings.lightbox : true;
            var imagesHtml = '';
            images.forEach(function(img, i) {
                imagesHtml += buildGalleryImageRow(img, i);
            });
            return '<div id="gallery-images-list">' + imagesHtml + '</div>' +
                '<div class="mt-2" style="display:flex;gap:8px;">' +
                '<button type="button" class="btn btn-sm btn-success" id="add-gallery-image"><i class="fas fa-plus mr-1"></i> Add Image</button>' +
                '<button type="button" class="btn btn-sm btn-outline-info" id="bulk-add-gallery"><i class="fas fa-images mr-1"></i> Bulk Add from Library</button>' +
                '</div>' +
                '<hr>' +
                '<div class="form-group mt-2"><label>Columns</label>' +
                    '<input type="number" class="form-control" id="gallery-columns" value="' + escHtml(String(columns)) + '" min="1" max="6">' +
                '</div>' +
                '<div class="form-group"><label>Gap (px)</label>' +
                    '<input type="number" class="form-control" id="gallery-gap" value="' + escHtml(String(gap)) + '" min="0">' +
                '</div>' +
                '<div class="form-check">' +
                    '<input type="checkbox" class="form-check-input" id="gallery-lightbox"' + (lightbox ? ' checked' : '') + '>' +
                    '<label class="form-check-label" for="gallery-lightbox">Enable Lightbox</label>' +
                '</div>';
        },
        initEditor: function(block) {
            initGalleryEditor(block);
        },
        collectData: function(block) {
            var images = [];
            $('#gallery-images-list .gallery-image-row').each(function() {
                var altText = $(this).find('.gal-alt').val();
                images.push({
                    url: $(this).find('.gal-url').val(),
                    alt: altText,
                    caption: altText
                });
            });
            return {
                content: { images: images },
                settings: {
                    columns: parseInt($('#gallery-columns').val()) || 3,
                    gap: parseInt($('#gallery-gap').val()) || 10,
                    lightbox: $('#gallery-lightbox').is(':checked')
                }
            };
        }
    });

    PageEditor.registerBlockType('testimonials', {
        icon: 'fa-quote-right',
        label: 'Testimonials',
        defaults: { content: { testimonials: [] }, settings: { layout: 'cards' } },
        renderPreview: function(block) {
            var testimonials = block.content && block.content.testimonials ? block.content.testimonials : [];
            if (!testimonials.length) return '<em class="text-muted">No testimonials added</em>';
            var cardsHtml = '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
            testimonials.forEach(function(t) {
                var quote = t.quote || '';
                var truncated = quote.length > 100 ? quote.substring(0, 100) + '...' : quote;
                cardsHtml += '<div style="border-left:3px solid #3b82f6;padding:8px 12px;background:#f9fafb;border-radius:4px;min-width:180px;max-width:260px;flex:1;">';
                cardsHtml += '<div style="font-style:italic;font-size:0.85em;color:#374151;margin-bottom:6px;">' + escHtml(truncated) + '</div>';
                cardsHtml += '<div style="display:flex;align-items:center;gap:6px;">';
                if (t.photo_url) {
                    cardsHtml += '<img src="' + escHtml(t.photo_url) + '" style="width:24px;height:24px;border-radius:50%;object-fit:cover;">';
                }
                cardsHtml += '<span style="font-weight:600;font-size:0.8em;">' + escHtml(t.name || '') + '</span>';
                if (t.title) cardsHtml += '<span style="font-size:0.75em;color:#6b7280;">' + escHtml(t.title) + '</span>';
                cardsHtml += '</div></div>';
            });
            cardsHtml += '</div>';
            return cardsHtml;
        },
        renderEditor: function(block) {
            var testimonials = block.content && block.content.testimonials ? block.content.testimonials : [];
            var testiHtml = '';
            testimonials.forEach(function(t, i) {
                testiHtml += buildTestimonialRow(t, i);
            });
            return '<div id="testimonials-list">' + testiHtml + '</div>' +
                '<button type="button" class="btn btn-sm btn-success mt-2" id="add-testimonial">+ Add Testimonial</button>';
        },
        initEditor: function(block) {
            initTestimonialsEditor(block);
        },
        collectData: function(block) {
            var testimonials = [];
            $('#testimonials-list .testi-row').each(function() {
                testimonials.push({
                    quote: $(this).find('.testi-quote').val(),
                    name: $(this).find('.testi-name').val(),
                    title: $(this).find('.testi-title').val(),
                    photo_url: $(this).find('.testi-photo').val()
                });
            });
            return {
                content: { testimonials: testimonials },
                settings: { layout: 'cards' }
            };
        }
    });

    // A tier is a card carrying a price: a name, what it costs, what it
    // includes, and a way to act on it. Registered late in the day — the
    // block rendered on the public site from the start, but with no editor
    // its owner opened the page to "Unknown block type".
    function buildPricingTierRow(t) {
        var features = Array.isArray(t.features) ? t.features.join('\n') : (t.features || '');
        return '<div class="pt-row" style="margin-bottom:10px;padding:10px;background:#f8f9fa;border-radius:6px;border-left:3px solid #3b82f6;">' +
            '<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">' +
                '<input type="text" class="form-control form-control-sm pt-name" placeholder="Name" value="' + escHtml(t.name || '') + '" style="flex:2;">' +
                '<input type="text" class="form-control form-control-sm pt-currency" placeholder="$" value="' + escHtml(t.price_currency || '') + '" style="flex:0 0 64px;">' +
                '<input type="text" class="form-control form-control-sm pt-price" placeholder="Price" value="' + escHtml(t.price || '') + '" style="flex:1;">' +
                '<input type="text" class="form-control form-control-sm pt-period" placeholder="per month" value="' + escHtml(t.period || '') + '" style="flex:1;">' +
                '<button type="button" class="btn btn-sm btn-outline-danger remove-pricing-tier" title="Remove"><i class="fas fa-times"></i></button>' +
            '</div>' +
            '<textarea class="form-control form-control-sm mb-2 pt-desc" rows="2" placeholder="Description">' + escHtml(t.description || '') + '</textarea>' +
            '<textarea class="form-control form-control-sm mb-2 pt-features" rows="3" placeholder="One feature per line">' + escHtml(features) + '</textarea>' +
            '<div style="display:flex;gap:8px;align-items:center;">' +
                '<input type="text" class="form-control form-control-sm pt-cta-text" placeholder="Button text" value="' + escHtml(t.cta_text || '') + '" style="flex:1;">' +
                '<input type="text" class="form-control form-control-sm pt-cta-url" placeholder="Button link" value="' + escHtml(t.cta_url || '') + '" style="flex:2;">' +
                '<label class="mb-0 small text-nowrap" style="cursor:pointer;">' +
                    '<input type="checkbox" class="pt-featured"' + (t.featured ? ' checked' : '') + '> Featured' +
                '</label>' +
            '</div>' +
        '</div>';
    }

    PageEditor.registerBlockType('pricing_tiers', {
        icon: 'fa-tags',
        label: 'Pricing Tiers',
        defaults: { content: { tiers: [] }, settings: { columns: 3 } },
        renderPreview: function(block) {
            var tiers = block.content && block.content.tiers ? block.content.tiers : [];
            if (!tiers.length) return '<em class="text-muted">No tiers added</em>';
            var cols = block.settings && block.settings.columns ? block.settings.columns : 3;
            var html = '<div style="display:grid;grid-template-columns:repeat(' + Math.min(cols, tiers.length) + ',1fr);gap:8px;">';
            tiers.forEach(function(t) {
                html += '<div style="padding:10px;background:#f9fafb;border-radius:4px;border:1px solid ' + (t.featured ? '#3b82f6' : '#e5e7eb') + ';">';
                html += '<div style="font-weight:600;font-size:0.85em;margin-bottom:4px;">' + escHtml(t.name || '') + '</div>';
                html += '<div style="font-size:1.1em;font-weight:700;color:#3b82f6;">' + escHtml((t.price_currency || '') + (t.price || '')) + '<span style="font-size:0.6em;font-weight:400;color:#6b7280;"> ' + escHtml(t.period || '') + '</span></div>';
                if (t.description) {
                    var d = t.description.length > 60 ? t.description.substring(0, 60) + '...' : t.description;
                    html += '<div style="font-size:0.78em;color:#6b7280;margin-top:4px;">' + escHtml(d) + '</div>';
                }
                var count = Array.isArray(t.features) ? t.features.length : 0;
                if (count) html += '<div style="font-size:0.72em;color:#9ca3af;margin-top:4px;">' + count + ' feature' + (count === 1 ? '' : 's') + '</div>';
                html += '</div>';
            });
            return html + '</div>';
        },
        renderEditor: function(block) {
            var tiers = block.content && block.content.tiers ? block.content.tiers : [];
            var cols = block.settings && block.settings.columns ? block.settings.columns : 3;
            var rowsHtml = '';
            tiers.forEach(function(t) { rowsHtml += buildPricingTierRow(t); });
            return '<div id="pricing-tiers-list">' + rowsHtml + '</div>' +
                '<div style="display:flex;gap:8px;align-items:center;margin-top:8px;">' +
                    '<button type="button" class="btn btn-sm btn-success" id="add-pricing-tier">+ Add Tier</button>' +
                    '<label class="mb-0 small text-nowrap">Columns</label>' +
                    '<select class="form-control form-control-sm" id="pricing-tiers-columns" style="width:auto;">' +
                        [2, 3, 4].map(function(n) {
                            return '<option value="' + n + '"' + (cols === n ? ' selected' : '') + '>' + n + '</option>';
                        }).join('') +
                    '</select>' +
                '</div>';
        },
        initEditor: function(block) {
            $(document).off('click', '#add-pricing-tier').on('click', '#add-pricing-tier', function() {
                $('#pricing-tiers-list').append(buildPricingTierRow({ features: [] }));
            });
            $(document).off('click', '.remove-pricing-tier').on('click', '.remove-pricing-tier', function() {
                $(this).closest('.pt-row').remove();
            });
        },
        collectData: function(block) {
            var tiers = [];
            $('#pricing-tiers-list .pt-row').each(function() {
                var $r = $(this);
                var features = ($r.find('.pt-features').val() || '')
                    .split('\n')
                    .map(function(line) { return line.trim(); })
                    .filter(function(line) { return line !== ''; });
                tiers.push({
                    name: $r.find('.pt-name').val(),
                    price: $r.find('.pt-price').val(),
                    price_currency: $r.find('.pt-currency').val(),
                    period: $r.find('.pt-period').val(),
                    description: $r.find('.pt-desc').val(),
                    features: features,
                    cta_text: $r.find('.pt-cta-text').val(),
                    cta_url: $r.find('.pt-cta-url').val(),
                    featured: $r.find('.pt-featured').is(':checked')
                });
            });
            return {
                content: { tiers: tiers },
                settings: { columns: parseInt($('#pricing-tiers-columns').val(), 10) || 3 }
            };
        }
    });

    PageEditor.registerBlockType('icon_box', {
        icon: 'fa-icons',
        label: 'Icon Box',
        defaults: { content: { items: [] }, settings: { columns: 3, layout: 'vertical' } },
        renderPreview: function(block) {
            var items = block.content && block.content.items ? block.content.items : [];
            if (!items.length) return '<em class="text-muted">No icon boxes added</em>';
            var cols = block.settings && block.settings.columns ? block.settings.columns : 3;
            var gridCols = Math.min(cols, items.length);
            var ibHtml = '<div style="display:grid;grid-template-columns:repeat(' + gridCols + ',1fr);gap:8px;">';
            items.forEach(function(item) {
                var desc = item.description || '';
                var truncDesc = desc.length > 60 ? desc.substring(0, 60) + '...' : desc;
                ibHtml += '<div style="text-align:center;padding:10px 8px;background:#f9fafb;border-radius:4px;">';
                ibHtml += '<i class="' + escHtml(item.icon || 'fas fa-star') + '" style="font-size:1.5em;color:#3b82f6;margin-bottom:6px;display:block;"></i>';
                ibHtml += '<div style="font-weight:600;font-size:0.85em;margin-bottom:4px;">' + escHtml(item.title || '') + '</div>';
                ibHtml += '<div style="font-size:0.78em;color:#6b7280;">' + escHtml(truncDesc) + '</div>';
                ibHtml += '</div>';
            });
            ibHtml += '</div>';
            return ibHtml;
        },
        renderEditor: function(block) {
            var items = block.content && block.content.items ? block.content.items : [];
            var columns = block.settings && block.settings.columns ? block.settings.columns : 3;
            var layout = block.settings && block.settings.layout ? block.settings.layout : 'vertical';
            var rowsHtml = '';
            items.forEach(function(item, i) {
                rowsHtml += buildIconBoxRow(item, i);
            });
            return '<div id="iconbox-items-list">' + rowsHtml + '</div>' +
                '<button type="button" class="btn btn-sm btn-success mt-2" id="add-iconbox-item">+ Add Icon Box</button>' +
                '<hr>' +
                '<div class="form-row">' +
                    '<div class="form-group col-md-6">' +
                        '<label>Columns</label>' +
                        '<input type="number" class="form-control" id="iconbox-columns" value="' + escHtml(String(columns)) + '" min="1" max="6">' +
                    '</div>' +
                    '<div class="form-group col-md-6">' +
                        '<label>Layout</label>' +
                        '<select class="form-control" id="iconbox-layout">' +
                            '<option value="vertical"' + (layout === 'vertical' ? ' selected' : '') + '>Vertical (icon on top)</option>' +
                            '<option value="horizontal"' + (layout === 'horizontal' ? ' selected' : '') + '>Horizontal (icon on left)</option>' +
                        '</select>' +
                    '</div>' +
                '</div>' +
                '<small class="text-muted">Example FA classes: <code>fas fa-star</code>, <code>fas fa-heart</code>, <code>fas fa-globe</code>, <code>fas fa-anchor</code>, <code>fas fa-water</code></small>';
        },
        initEditor: function(block) {
            initIconBoxEditor(block);
        },
        collectData: function(block) {
            var iconBoxItems = [];
            $('#iconbox-items-list .ib-row').each(function() {
                iconBoxItems.push({
                    icon: $(this).find('.ib-icon').val(),
                    title: $(this).find('.ib-title').val(),
                    description: $(this).find('.ib-desc').val()
                });
            });
            return {
                content: { items: iconBoxItems },
                settings: {
                    columns: parseInt($('#iconbox-columns').val()) || 3,
                    layout: $('#iconbox-layout').val() || 'vertical'
                }
            };
        }
    });

    PageEditor.registerBlockType('categories_grid', {
        icon: 'fa-th-large',
        label: 'Categories Grid',
        defaults: { content: {}, settings: { columns: 3, max_count: 12, show_post_count: true } },
        renderPreview: function(block) {
            var s = block.settings || {};
            var cols = s.columns || 3;
            var max = s.max_count || 12;
            var showCount = s.show_post_count !== false;
            return '<div style="padding:8px;background:#f0f4f8;border-radius:4px;text-align:center;">' +
                '<i class="fas fa-th-large" style="font-size:1.5em;color:#6c757d;"></i>' +
                '<div style="font-size:0.85em;color:#555;margin-top:4px;">Categories Grid &mdash; ' + cols + ' cols, max ' + max +
                (showCount ? ', with count' : '') + '</div></div>';
        },
        renderEditor: function(block) {
            var s = block.settings || {};
            var columns = s.columns || 3;
            var maxCount = s.max_count || 12;
            var showPostCount = s.show_post_count !== false;
            return '<div class="form-group"><label>Columns</label>' +
                '<input type="number" class="form-control" id="catgrid-columns" value="' + columns + '" min="1" max="6"></div>' +
                '<div class="form-group"><label>Max Categories</label>' +
                '<input type="number" class="form-control" id="catgrid-max" value="' + maxCount + '" min="1" max="50"></div>' +
                '<div class="form-check">' +
                    '<input type="checkbox" class="form-check-input" id="catgrid-count"' + (showPostCount ? ' checked' : '') + '>' +
                    '<label class="form-check-label" for="catgrid-count">Show post count</label>' +
                '</div>';
        },
        initEditor: function(block) {},
        collectData: function(block) {
            return {
                content: {},
                settings: {
                    columns: parseInt($('#catgrid-columns').val()) || 3,
                    max_count: parseInt($('#catgrid-max').val()) || 12,
                    show_post_count: $('#catgrid-count').is(':checked')
                }
            };
        }
    });

    PageEditor.registerBlockType('posts_grid', {
        icon: 'fa-newspaper',
        label: 'Posts Grid',
        defaults: { content: {}, settings: { columns: 3, max_count: 12, category_id: '', order_by: 'newest', show_excerpt: true } },
        renderPreview: function(block) {
            var s = block.settings || {};
            var cols = s.columns || 3;
            var max = s.max_count || 12;
            var order = s.order_by || 'newest';
            var catId = s.category_id || 'all';
            return '<div style="padding:8px;background:#f0f4f8;border-radius:4px;text-align:center;">' +
                '<i class="fas fa-newspaper" style="font-size:1.5em;color:#6c757d;"></i>' +
                '<div style="font-size:0.85em;color:#555;margin-top:4px;">Posts Grid &mdash; ' + cols + ' cols, max ' + max +
                ', ' + order + ', cat: ' + catId + '</div></div>';
        },
        renderEditor: function(block) {
            var s = block.settings || {};
            var columns = s.columns || 3;
            var maxCount = s.max_count || 12;
            var categoryId = s.category_id || '';
            var orderBy = s.order_by || 'newest';
            var showExcerpt = s.show_excerpt !== false;
            var catOptions = '<option value="">All categories</option>';
            if (window.PageEditorConfig && window.PageEditorConfig.categories) {
                window.PageEditorConfig.categories.forEach(function(cat) {
                    catOptions += '<option value="' + cat.id + '"' + (String(categoryId) === String(cat.id) ? ' selected' : '') + '>' + escHtml(cat.name) + '</option>';
                });
            }
            return '<div class="form-group"><label>Columns</label>' +
                '<input type="number" class="form-control" id="postgrid-columns" value="' + columns + '" min="1" max="6"></div>' +
                '<div class="form-group"><label>Max Posts</label>' +
                '<input type="number" class="form-control" id="postgrid-max" value="' + maxCount + '" min="1" max="50"></div>' +
                '<div class="form-group"><label>Category Filter</label>' +
                '<select class="form-control" id="postgrid-category">' + catOptions + '</select></div>' +
                '<div class="form-group"><label>Order By</label>' +
                '<select class="form-control" id="postgrid-order">' +
                    '<option value="newest"' + (orderBy === 'newest' ? ' selected' : '') + '>Newest first</option>' +
                    '<option value="oldest"' + (orderBy === 'oldest' ? ' selected' : '') + '>Oldest first</option>' +
                    '<option value="title_asc"' + (orderBy === 'title_asc' ? ' selected' : '') + '>Title A-Z</option>' +
                    '<option value="title_desc"' + (orderBy === 'title_desc' ? ' selected' : '') + '>Title Z-A</option>' +
                '</select></div>' +
                '<div class="form-check">' +
                    '<input type="checkbox" class="form-check-input" id="postgrid-excerpt"' + (showExcerpt ? ' checked' : '') + '>' +
                    '<label class="form-check-label" for="postgrid-excerpt">Show excerpt</label>' +
                '</div>';
        },
        initEditor: function(block) {},
        collectData: function(block) {
            return {
                content: {},
                settings: {
                    columns: parseInt($('#postgrid-columns').val()) || 3,
                    max_count: parseInt($('#postgrid-max').val()) || 12,
                    category_id: $('#postgrid-category').val() || '',
                    order_by: $('#postgrid-order').val() || 'newest',
                    show_excerpt: $('#postgrid-excerpt').is(':checked')
                }
            };
        }
    });

    PageEditor.registerBlockType('hero', {
        icon: 'fa-flag',
        label: 'Hero Banner',
        defaults: {
            content: { title: '', subtitle: '', primary_button_text: '', primary_button_url: '', secondary_button_text: '', secondary_button_url: '' },
            settings: { background_overlay: 'rgba(0,0,0,0.4)', text_alignment: 'center', min_height: '80vh' }
        },
        renderPreview: function(block) {
            var c = block.content || {};
            var s = block.settings || {};
            var title = c.title || 'Hero Banner';
            var subtitle = c.subtitle || '';
            var overlay = s.background_overlay || 'rgba(0,0,0,0.4)';
            return '<div style="position:relative;padding:24px 16px;background:linear-gradient(135deg,#1e3a5f,#2d5f8a);color:#fff;border-radius:6px;text-align:' + (s.text_alignment || 'center') + ';">' +
                '<div style="position:relative;z-index:1;">' +
                '<div style="font-size:1.3em;font-weight:700;margin-bottom:6px;">' + escHtml(title) + '</div>' +
                (subtitle ? '<div style="font-size:0.9em;opacity:0.85;">' + escHtml(subtitle) + '</div>' : '') +
                '</div></div>';
        },
        renderEditor: function(block) {
            var c = block.content || {};
            var s = block.settings || {};
            return '<div class="form-group"><label>Title</label><input type="text" class="form-control" id="hero-title" value="' + escHtml(c.title || '') + '"></div>' +
                '<div class="form-group"><label>Subtitle</label><textarea class="form-control" id="hero-subtitle" rows="2">' + escHtml(c.subtitle || '') + '</textarea></div>' +
                '<hr><strong>Buttons</strong>' +
                '<div class="form-row mt-2"><div class="form-group col-md-6"><label>Primary Button Text</label><input type="text" class="form-control" id="hero-btn1-text" value="' + escHtml(c.primary_button_text || '') + '"></div>' +
                '<div class="form-group col-md-6"><label>Primary Button URL</label><input type="text" class="form-control" id="hero-btn1-url" value="' + escHtml(c.primary_button_url || '') + '"></div></div>' +
                '<div class="form-row"><div class="form-group col-md-6"><label>Secondary Button Text</label><input type="text" class="form-control" id="hero-btn2-text" value="' + escHtml(c.secondary_button_text || '') + '"></div>' +
                '<div class="form-group col-md-6"><label>Secondary Button URL</label><input type="text" class="form-control" id="hero-btn2-url" value="' + escHtml(c.secondary_button_url || '') + '"></div></div>' +
                '<hr><strong>Settings</strong>' +
                '<div class="form-row mt-2"><div class="form-group col-md-4"><label>Overlay Color</label><input type="text" class="form-control" id="hero-overlay" value="' + escHtml(s.background_overlay || 'rgba(0,0,0,0.4)') + '" placeholder="rgba(0,0,0,0.4)"></div>' +
                '<div class="form-group col-md-4"><label>Text Alignment</label><select class="form-control" id="hero-align">' +
                    '<option value="left"' + (s.text_alignment === 'left' ? ' selected' : '') + '>Left</option>' +
                    '<option value="center"' + (s.text_alignment !== 'left' && s.text_alignment !== 'right' ? ' selected' : '') + '>Center</option>' +
                    '<option value="right"' + (s.text_alignment === 'right' ? ' selected' : '') + '>Right</option></select></div>' +
                '<div class="form-group col-md-4"><label>Min Height</label><input type="text" class="form-control" id="hero-height" value="' + escHtml(s.min_height || '80vh') + '" placeholder="80vh"></div></div>' +
                // The banner image is what people come here to change, so it sits in the
                // block form itself rather than behind the collapsed Block Style panel.
                imageField('block-bg-image', 'Background Image', block.background_image);
        },
        initEditor: function(block) {},
        collectData: function(block) {
            return {
                content: {
                    title: $('#hero-title').val(),
                    subtitle: $('#hero-subtitle').val(),
                    primary_button_text: $('#hero-btn1-text').val(),
                    primary_button_url: $('#hero-btn1-url').val(),
                    secondary_button_text: $('#hero-btn2-text').val(),
                    secondary_button_url: $('#hero-btn2-url').val()
                },
                settings: {
                    background_overlay: $('#hero-overlay').val() || 'rgba(0,0,0,0.4)',
                    text_alignment: $('#hero-align').val() || 'center',
                    min_height: $('#hero-height').val() || '80vh'
                }
            };
        }
    });

    PageEditor.registerBlockType('cta', {
        icon: 'fa-bullhorn',
        label: 'Call to Action',
        defaults: {
            content: { heading: '', description: '', primary_button_text: '', primary_button_url: '', secondary_button_text: '', secondary_button_url: '' },
            settings: { text_alignment: 'center' }
        },
        renderPreview: function(block) {
            var c = block.content || {};
            var heading = c.heading || 'Call to Action';
            var desc = c.description || '';
            return '<div style="padding:16px;background:#f0f4f8;border-radius:6px;text-align:' + ((block.settings || {}).text_alignment || 'center') + ';">' +
                '<div style="font-size:1.2em;font-weight:700;margin-bottom:4px;">' + escHtml(heading) + '</div>' +
                (desc ? '<div style="font-size:0.85em;color:#555;">' + escHtml(desc.length > 100 ? desc.substring(0, 100) + '...' : desc) + '</div>' : '') +
                '</div>';
        },
        renderEditor: function(block) {
            var c = block.content || {};
            var s = block.settings || {};
            return '<div class="form-group"><label>Heading</label><input type="text" class="form-control" id="cta-heading" value="' + escHtml(c.heading || '') + '"></div>' +
                '<div class="form-group"><label>Description</label><textarea class="form-control" id="cta-description" rows="2">' + escHtml(c.description || '') + '</textarea></div>' +
                '<hr><strong>Buttons</strong>' +
                '<div class="form-row mt-2"><div class="form-group col-md-6"><label>Primary Button Text</label><input type="text" class="form-control" id="cta-btn1-text" value="' + escHtml(c.primary_button_text || '') + '"></div>' +
                '<div class="form-group col-md-6"><label>Primary Button URL</label><input type="text" class="form-control" id="cta-btn1-url" value="' + escHtml(c.primary_button_url || '') + '"></div></div>' +
                '<div class="form-row"><div class="form-group col-md-6"><label>Secondary Button Text</label><input type="text" class="form-control" id="cta-btn2-text" value="' + escHtml(c.secondary_button_text || '') + '"></div>' +
                '<div class="form-group col-md-6"><label>Secondary Button URL</label><input type="text" class="form-control" id="cta-btn2-url" value="' + escHtml(c.secondary_button_url || '') + '"></div></div>' +
                '<hr>' +
                '<div class="form-group"><label>Text Alignment</label><select class="form-control" id="cta-align">' +
                    '<option value="left"' + (s.text_alignment === 'left' ? ' selected' : '') + '>Left</option>' +
                    '<option value="center"' + (s.text_alignment !== 'left' && s.text_alignment !== 'right' ? ' selected' : '') + '>Center</option>' +
                    '<option value="right"' + (s.text_alignment === 'right' ? ' selected' : '') + '>Right</option></select></div>';
        },
        initEditor: function(block) {},
        collectData: function(block) {
            return {
                content: {
                    heading: $('#cta-heading').val(),
                    description: $('#cta-description').val(),
                    primary_button_text: $('#cta-btn1-text').val(),
                    primary_button_url: $('#cta-btn1-url').val(),
                    secondary_button_text: $('#cta-btn2-text').val(),
                    secondary_button_url: $('#cta-btn2-url').val()
                },
                settings: {
                    text_alignment: $('#cta-align').val() || 'center'
                }
            };
        }
    });

    // =========================================================================
    // Core editor functions (use registry)
    // =========================================================================

    // --- Init ---
    function init(existingRows) {
        if (existingRows && existingRows.length) {
            rows = parseExistingRows(existingRows);
        }
        renderRows();
        initRowSortable();
        bindFormEvents();
    }

    function parseExistingRows(data) {
        return data.map(function(row) {
            var colMap = {};
            (row.blocks || []).forEach(function(b) {
                var ci = b.column_index || 0;
                if (!colMap[ci]) colMap[ci] = { width: b.column_width || 12, blocks: [] };
                colMap[ci].blocks.push({
                    id: b.id,
                    type: b.type,
                    content: b.content || {},
                    settings: b.settings || {},
                    background_color: b.background_color || '',
                    background_image: b.background_image || '',
                    text_color: b.text_color || '',
                    text_alignment: b.text_alignment || '',
                    padding: b.padding || '',
                    order: b.order_column || 0
                });
            });
            var columns = [];
            var indices = Object.keys(colMap).map(Number).sort(function(a, b) { return a - b; });
            indices.forEach(function(ci) {
                var col = colMap[ci];
                col.blocks.sort(function(a, b) { return a.order - b.order; });
                columns.push({ width: col.width, blocks: col.blocks });
            });
            if (!columns.length) columns = [{ width: 12, blocks: [] }];
            return {
                id: row.id,
                name: row.name || '',
                css_class: row.css_class || '',
                background_color: row.background_color || '',
                background_image: row.background_image || '',
                text_color: row.text_color || '',
                text_alignment: row.text_alignment || '',
                padding: row.padding || '',
                width: row.width || 'contained',
                order: row.order_column || 0,
                columns: columns
            };
        });
    }

    // --- Row Management ---
    function addRow() {
        var newRow = { id: uid(), name: '', css_class: '', background_color: '', background_image: '', text_color: '', text_alignment: '', padding: '', width: 'contained', order: rows.length, columns: [{ width: 12, blocks: [] }] };
        rows.push(newRow);
        renderRows();
        initRowSortable();
        openColumnLayoutModal(newRow.id);
    }

    function removeRow(rowId) {
        if (!confirm('Remove this row and all its blocks?')) return;
        rows = rows.filter(function(r) { return r.id != rowId; });
        renderRows();
        initRowSortable();
    }

    function renderRows() {
        var $container = $('#rows-container');
        $container.empty();
        rows.forEach(function(row, ri) {
            $container.append(buildRowHtml(row, ri));
        });
        rows.forEach(function(row) {
            initBlockSortable(row.id);
        });

        // Nearly every change to the page ends here, so one hook covers adding,
        // removing, duplicating and reordering without each of them having to
        // remember to record itself.
        notePageHistory();
    }

    // --- Undo: the page and its blocks --------------------------------------

    function pageState() {
        return JSON.stringify(rows);
    }

    function notePageHistory() {
        noteHistory(_pageHistory, pageState());
        updateHistoryButtons();
    }

    function applyPageState(json) {
        _restoring = true;
        try {
            rows = JSON.parse(json);
            renderRows();
            initRowSortable();
        } finally {
            _restoring = false;
        }
        updateHistoryButtons();
    }

    // --- Undo: one imported section -----------------------------------------

    function blockState() {
        if (!_htmlDoc) return null;
        return JSON.stringify({ html: serializeBlockHtml(_htmlDoc), hidden: _htmlHidden });
    }

    function noteBlockHistory() {
        noteHistory(_blockHistory, blockState());
        updateHistoryButtons();
    }

    function applyBlockState(json) {
        var state = JSON.parse(json);
        _restoring = true;
        try {
            _htmlDoc = parseBlockHtml(state.html);
            _htmlHidden = (state.hidden || []).slice();
            // Rebuilt FROM the restored markup. The ordinary redraw folds the
            // form back into the document first, which on the way out of an
            // undo would put the wording that was just undone straight back.
            redrawImportedEditor(true);
        } finally {
            _restoring = false;
        }
        updateHistoryButtons();
    }

    function historyScope() {
        return ($('#block-edit-modal').hasClass('show') && _htmlDoc) ? 'block' : 'page';
    }

    function runUndo() {
        return historyScope() === 'block'
            ? undoHistory(_blockHistory, blockState(), applyBlockState)
            : undoHistory(_pageHistory, pageState(), applyPageState);
    }

    function runRedo() {
        return historyScope() === 'block'
            ? redoHistory(_blockHistory, blockState(), applyBlockState)
            : redoHistory(_pageHistory, pageState(), applyPageState);
    }

    function updateHistoryButtons() {
        var block = historyScope() === 'block';
        var store = block ? _blockHistory : _pageHistory;
        var current = block ? blockState() : pageState();
        // A change still inside the merge window is undoable even though it has
        // not been pushed, or the button would sit greyed out right after an edit.
        var pending = store.last !== null && current !== store.last;

        $('.vela-undo-btn').prop('disabled', !(store.past.length || pending));
        $('.vela-redo-btn').prop('disabled', !store.future.length);
    }

    function buildRowHtml(row, ri) {
        var colsHtml = '';
        row.columns.forEach(function(col, ci) {
            var blocksHtml = '';
            col.blocks.forEach(function(block, bi) {
                blocksHtml += buildBlockHtml(block, ci, bi);
            });
            var mdWidth = Math.round((col.width / 12) * 12);
            var pctWidth = Math.round((col.width / 12) * 100);
            colsHtml += '<div class="col-md-' + mdWidth + ' page-column-editor" data-col-index="' + ci + '">' +
                '<div class="column-header-label">Col ' + (ci + 1) + ' (' + pctWidth + '%)</div>' +
                '<div class="blocks-sortable" data-row-id="' + row.id + '" data-col-index="' + ci + '">' +
                    blocksHtml +
                '</div>' +
                '<button type="button" class="btn btn-sm btn-outline-success btn-block mt-1 add-block-btn" data-row-id="' + row.id + '" data-col-index="' + ci + '">' +
                    '<i class="fas fa-plus"></i> Add Block' +
                '</button>' +
                '</div>';
        });

        var bgIndicator = '';
        if (row.background_color || row.background_image) {
            var bgParts = [];
            if (row.background_color) bgParts.push('<span class="row-bg-swatch" style="display:inline-block;width:14px;height:14px;border-radius:3px;border:1px solid #ccc;vertical-align:middle;background:' + escHtml(row.background_color) + ';"></span>');
            if (row.background_image) bgParts.push('<i class="fas fa-image" style="font-size:0.8em;color:#6c757d;vertical-align:middle;"></i>');
            bgIndicator = '<span class="ml-2" title="Background set">' + bgParts.join(' ') + '</span>';
        }

        return '<div class="card mb-3 page-row-editor" data-row-id="' + row.id + '">' +
            '<div class="card-header d-flex justify-content-between align-items-center">' +
                '<div class="d-flex align-items-center">' +
                    '<span class="drag-handle mr-2"><i class="fas fa-grip-vertical"></i></span>' +
                    '<input type="text" class="form-control form-control-sm row-name-input" placeholder="Row name (optional)" value="' + escHtml(row.name) + '" style="width:200px;" data-row-id="' + row.id + '">' +
                    bgIndicator +
                '</div>' +
                '<div>' +
                    '<button type="button" class="btn btn-sm btn-outline-info row-bg-btn mr-1" data-row-id="' + row.id + '" title="Row Style"><i class="fas fa-paint-roller"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary column-layout-btn mr-1" data-row-id="' + row.id + '" title="Column Layout"><i class="fas fa-columns"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-danger remove-row-btn" data-row-id="' + row.id + '" title="Remove Row"><i class="fas fa-trash"></i></button>' +
                '</div>' +
            '</div>' +
            '<div class="card-body py-2">' +
                '<div class="row">' + colsHtml + '</div>' +
            '</div>' +
        '</div>';
    }

    function buildBlockHtml(block, ci, bi) {
        var config = PageEditor.blockTypes[block.type];
        var icon = config ? config.icon : 'fa-cube';
        var label = config ? config.label : block.type;
        var preview = getBlockPreview(block);
        return '<div class="page-block-editor-item" data-col-index="' + ci + '" data-block-index="' + bi + '">' +
            '<div class="block-header">' +
                '<small class="drag-handle"><i class="fas fa-grip-vertical mr-1"></i></small>' +
                '<small><i class="fas ' + icon + '"></i> ' + label + '</small>' +
                '<div>' +
                    '<button type="button" class="btn btn-xs btn-info edit-block-btn" title="Edit"><i class="fas fa-edit"></i></button> ' +
                    '<button type="button" class="btn btn-xs btn-warning duplicate-block-btn" title="Duplicate"><i class="fas fa-copy"></i></button> ' +
                    '<button type="button" class="btn btn-xs btn-danger remove-block-btn" title="Remove"><i class="fas fa-trash"></i></button>' +
                '</div>' +
            '</div>' +
            '<div class="block-preview-text">' + preview + '</div>' +
        '</div>';
    }

    function getBlockPreview(block) {
        var config = PageEditor.blockTypes[block.type];
        if (config && config.renderPreview) {
            return config.renderPreview(block);
        }
        return '';
    }

    // --- Sortable ---
    function initRowSortable() {
        var el = document.getElementById('rows-container');
        if (el && el._sortable) el._sortable.destroy();
        if (!el) return;
        var s = Sortable.create(el, {
            handle: '.drag-handle',
            animation: 150,
            onEnd: function(evt) {
                var moved = rows.splice(evt.oldIndex, 1)[0];
                rows.splice(evt.newIndex, 0, moved);
                rows.forEach(function(r, i) { r.order = i; });
                // Sortable has already moved the markup, so nothing re-renders
                // here and this is the only chance to record the move.
                notePageHistory();
            }
        });
        el._sortable = s;
    }

    function initBlockSortable(rowId) {
        var row = getRow(rowId);
        if (!row) return;
        row.columns.forEach(function(col, ci) {
            var el = document.querySelector('[data-row-id="' + rowId + '"][data-col-index="' + ci + '"].blocks-sortable');
            if (!el) return;
            if (el._sortable) el._sortable.destroy();
            var s = Sortable.create(el, {
                handle: '.drag-handle',
                animation: 150,
                group: 'blocks-' + rowId,
                onEnd: function(evt) {
                    var fromCi = parseInt(evt.from.getAttribute('data-col-index'));
                    var toCi = parseInt(evt.to.getAttribute('data-col-index'));
                    var block = row.columns[fromCi].blocks.splice(evt.oldIndex, 1)[0];
                    row.columns[toCi].blocks.splice(evt.newIndex, 0, block);
                    row.columns[toCi].blocks.forEach(function(b, i) { b.order = i; });
                    if (fromCi !== toCi) {
                        row.columns[fromCi].blocks.forEach(function(b, i) { b.order = i; });
                    }
                    renderRows();
                    initRowSortable();
                }
            });
            el._sortable = s;
        });
    }

    // --- Block management ---
    function getRow(rowId) {
        return rows.find(function(r) { return r.id == rowId; });
    }

    function removeBlock(rowId, colIndex, blockIndex) {
        var row = getRow(rowId);
        if (!row || !confirm('Remove this block?')) return;
        row.columns[colIndex].blocks.splice(blockIndex, 1);
        renderRows();
        initRowSortable();
    }

    function duplicateBlock(rowId, colIndex, blockIndex) {
        var row = getRow(rowId);
        if (!row) return;
        var block = row.columns[colIndex].blocks[blockIndex];
        var copy = JSON.parse(JSON.stringify(block));
        copy.id = uid();
        copy.order = row.columns[colIndex].blocks.length;
        row.columns[colIndex].blocks.splice(blockIndex + 1, 0, copy);
        renderRows();
        initRowSortable();
    }

    // --- Column Layout ---
    var _layoutTargetRowId = null;
    function openColumnLayoutModal(rowId) {
        _layoutTargetRowId = rowId;
        $('#column-layout-modal').modal('show');
    }

    function setColumnLayout(rowId, widths) {
        var row = getRow(rowId);
        if (!row) return;
        var allBlocks = [];
        row.columns.forEach(function(c) { allBlocks = allBlocks.concat(c.blocks); });
        row.columns = widths.map(function(w) { return { width: w, blocks: [] }; });
        allBlocks.forEach(function(b, i) { row.columns[i % widths.length].blocks.push(b); });
        renderRows();
        initRowSortable();
    }

    // --- Edit Modal (uses registry) ---
    function openEditModal(rowId, colIndex, blockIndex) {
        var row = getRow(rowId);
        if (!row) return;
        var block = row.columns[colIndex].blocks[blockIndex];
        if (!block) return;
        editingRowId = rowId;
        editingColIndex = colIndex;
        editingBlockIndex = blockIndex;

        var config = PageEditor.blockTypes[block.type];
        var html = config ? config.renderEditor(block) : '<em>Unknown block type: ' + escHtml(block.type) + '</em>';

        // Block style fields
        html += '<hr><details class="mt-2"><summary style="cursor:pointer;font-weight:500;font-size:0.9em;"><i class="fas fa-paint-roller mr-1"></i> Block Style</summary><div class="mt-2">' +
            '<div class="form-group"><label>Background Color</label>' +
            '<div class="input-group"><input type="color" class="form-control form-control-color" id="block-bg-color" value="' + escHtml(swatchValue(block.background_color, '#ffffff')) + '" style="width:60px;padding:2px;">' +
            '<input type="text" class="form-control" id="block-bg-color-text" value="' + escHtml(block.background_color || '') + '" placeholder="#hex or empty for none">' +
            '<div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="block-bg-color-clear" title="Clear"><i class="fas fa-times"></i></button></div></div></div>' +
            (html.indexOf('id="block-bg-image"') === -1 ? imageField('block-bg-image', 'Background Image', block.background_image) : '') +
            '<div class="form-group"><label>Text Color</label>' +
            '<div class="input-group"><input type="color" class="form-control form-control-color" id="block-text-color" value="' + escHtml(swatchValue(block.text_color, '#000000')) + '" style="width:60px;padding:2px;">' +
            '<input type="text" class="form-control" id="block-text-color-text" value="' + escHtml(block.text_color || '') + '" placeholder="#hex or empty for default">' +
            '<div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="block-text-color-clear" title="Clear"><i class="fas fa-times"></i></button></div></div></div>' +
            '<div class="row"><div class="col-6"><div class="form-group"><label>Text Alignment</label>' +
            '<select class="form-control" id="block-text-align">' +
            '<option value=""' + (!block.text_alignment ? ' selected' : '') + '>Default</option>' +
            '<option value="left"' + (block.text_alignment === 'left' ? ' selected' : '') + '>Left</option>' +
            '<option value="center"' + (block.text_alignment === 'center' ? ' selected' : '') + '>Center</option>' +
            '<option value="right"' + (block.text_alignment === 'right' ? ' selected' : '') + '>Right</option>' +
            '</select></div></div>' +
            '<div class="col-6"><div class="form-group"><label>Padding</label>' +
            '<input type="text" class="form-control" id="block-padding" value="' + escHtml(block.padding || '') + '" placeholder="e.g. 20px">' +
            '</div></div></div>' +
            '</div></details>';

        $('.modal-title').text('Edit Block: ' + (config ? config.label : block.type));
        clearEditorPreviewPane();
        $('#block-edit-content').html(html);
        if (config && config.initEditor) { config.initEditor(block); }
        moveEditorPreviewIntoPane();

        // Block style color sync
        $('#block-bg-color').on('input', function() { $('#block-bg-color-text').val($(this).val()); });
        $('#block-bg-color-text').on('input', function() { var v = $(this).val(); if (/^#[0-9a-fA-F]{6}$/.test(v)) $('#block-bg-color').val(v); });
        $('#block-bg-color-clear').on('click', function() { $('#block-bg-color-text').val(''); });
        $('#block-text-color').on('input', function() { $('#block-text-color-text').val($(this).val()); });
        $('#block-text-color-text').on('input', function() { var v = $(this).val(); if (/^#[0-9a-fA-F]{6}$/.test(v)) $('#block-text-color').val(v); });
        $('#block-text-color-clear').on('click', function() { $('#block-text-color-text').val(''); });

        $('#block-edit-modal').modal('show');
    }

    // --- Save Block (uses registry) ---
    function saveBlock() {
        var row = getRow(editingRowId);
        if (!row) return;

        // Row background mode
        if (editingColIndex === null && editingBlockIndex === null) {
            row.background_color = $('#row-bg-color-text').val() || '';
            row.background_image = $('#row-bg-image').val() || '';
            row.text_color = $('#row-text-color-text').val() || '';
            row.text_alignment = $('#row-text-align').val() || '';
            row.padding = $('#row-padding').val() || '';
            row.width = $('#row-width').val() === 'full' ? 'full' : 'contained';
            finalizeSave();
            return;
        }

        var block = row.columns[editingColIndex].blocks[editingBlockIndex];
        if (!block) return;

        // Collect block style fields
        block.background_color = $('#block-bg-color-text').val() || '';
        block.background_image = $('#block-bg-image').val() || '';
        block.text_color = $('#block-text-color-text').val() || '';
        block.text_alignment = $('#block-text-align').val() || '';
        block.padding = $('#block-padding').val() || '';

        var config = PageEditor.blockTypes[block.type];
        if (!config) { finalizeSave(); return; }

        var result = config.collectData(block);
        if (result && typeof result.then === 'function') {
            result.then(function(data) {
                if (data && data.content !== undefined) block.content = data.content;
                if (data && data.settings !== undefined) block.settings = data.settings;
                finalizeSave();
            });
        } else {
            if (result && result.content !== undefined) block.content = result.content;
            if (result && result.settings !== undefined) block.settings = result.settings;
            finalizeSave();
        }
    }

    function finalizeSave() {
        if (editorJsInstance) { try { editorJsInstance.destroy(); } catch(e) {} editorJsInstance = null; }
        // The work is in the page now, so this close has nothing to warn about.
        _committingBlock = true;
        $('#block-edit-modal').modal('hide');
        renderRows();
        initRowSortable();
    }

    // --- Collect Data ---
    function collectData() {
        var data = rows.map(function(row, ri) {
            var blocks = [];
            row.columns.forEach(function(col, ci) {
                col.blocks.forEach(function(block, bi) {
                    blocks.push({
                        id: block.id,
                        column_index: ci,
                        column_width: col.width,
                        order: bi,
                        type: block.type,
                        content: block.content,
                        settings: block.settings,
                        background_color: block.background_color || '',
                        background_image: block.background_image || '',
                        text_color: block.text_color || '',
                        text_alignment: block.text_alignment || '',
                        padding: block.padding || ''
                    });
                });
            });
            return {
                id: row.id,
                name: row.name,
                css_class: row.css_class,
                background_color: row.background_color || '',
                background_image: row.background_image || '',
                text_color: row.text_color || '',
                text_alignment: row.text_alignment || '',
                padding: row.padding || '',
                width: row.width || 'contained',
                order: ri,
                blocks: blocks
            };
        });
        return JSON.stringify(data);
    }

    // --- Event Handlers ---
    function bindFormEvents() {
        // Row events
        $(document).on('click', '#add-row-btn', function() { addRow(); });

        $(document).on('click', '.remove-row-btn', function() {
            removeRow($(this).data('row-id'));
        });

        $(document).on('click', '.column-layout-btn', function() {
            openColumnLayoutModal($(this).data('row-id'));
        });

        $(document).on('click', '.layout-btn', function() {
            var widths = $(this).data('widths');
            if (typeof widths === 'string') widths = JSON.parse(widths);
            setColumnLayout(_layoutTargetRowId, widths);
            $('#column-layout-modal').modal('hide');
        });

        // Block events — "Add Block" picker built dynamically from registry
        $(document).on('click', '.add-block-btn', function() {
            var rowId = $(this).data('row-id');
            var colIndex = parseInt($(this).data('col-index'));
            var buttonsHtml = '';
            Object.keys(PageEditor.blockTypes).forEach(function(type) {
                var cfg = PageEditor.blockTypes[type];
                buttonsHtml += '<button class="btn btn-outline-secondary block-type-btn" data-type="' + escHtml(type) + '">' +
                    '<i class="fas ' + escHtml(cfg.icon) + ' mr-1"></i>' + escHtml(cfg.label) + '</button>';
            });
            var html = '<div class="block-type-picker">' +
                '<p class="mb-2"><strong>Choose a block type:</strong></p>' +
                '<div class="d-flex flex-wrap" style="gap:8px;">' + buttonsHtml + '</div></div>';
            $('#block-edit-content').html(html);
            $('#block-edit-modal .modal-title').text('Add Block');
            clearEditorPreviewPane();
            $('#save-block-btn').hide();
            $(document).off('click.blocktype').on('click.blocktype', '.block-type-btn', function() {
                var type = $(this).data('type');
                $(document).off('click.blocktype');
                var row = getRow(rowId);
                if (!row) return;
                var cfg = PageEditor.blockTypes[type];
                if (!cfg) return;
                var defaults = cfg.defaults || { content: {}, settings: {} };
                var block = {
                    id: uid(),
                    type: type,
                    content: JSON.parse(JSON.stringify(defaults.content || {})),
                    settings: JSON.parse(JSON.stringify(defaults.settings || {})),
                    background_color: '',
                    background_image: '',
                    text_color: '',
                    text_alignment: '',
                    padding: '',
                    order: row.columns[colIndex].blocks.length
                };
                row.columns[colIndex].blocks.push(block);
                renderRows();
                initRowSortable();
                // Swap modal content to the edit form without closing/reopening
                var bi = row.columns[colIndex].blocks.length - 1;
                editingRowId = rowId;
                editingColIndex = colIndex;
                editingBlockIndex = bi;
                var editHtml = cfg.renderEditor(block);
                $('.modal-title').text('Edit Block: ' + cfg.label);
                $('#block-edit-content').html(editHtml);
                $('#save-block-btn').show();
                if (cfg.initEditor) { cfg.initEditor(block); }
            });
            $('#block-edit-modal').modal('show');
        });

        $(document).on('click', '.edit-block-btn', function() {
            var $block = $(this).closest('.page-block-editor-item');
            var $sortable = $block.closest('.blocks-sortable');
            var rowId = $sortable.data('row-id');
            var colIndex = parseInt($sortable.data('col-index'));
            var blockIndex = $block.index();
            openEditModal(rowId, colIndex, blockIndex);
        });

        $(document).on('click', '.duplicate-block-btn', function() {
            var $block = $(this).closest('.page-block-editor-item');
            var $sortable = $block.closest('.blocks-sortable');
            var rowId = $sortable.data('row-id');
            var colIndex = parseInt($sortable.data('col-index'));
            var blockIndex = $block.index();
            duplicateBlock(rowId, colIndex, blockIndex);
        });

        $(document).on('click', '.remove-block-btn', function() {
            var $block = $(this).closest('.page-block-editor-item');
            var $sortable = $block.closest('.blocks-sortable');
            var rowId = $sortable.data('row-id');
            var colIndex = parseInt($sortable.data('col-index'));
            var blockIndex = $block.index();
            removeBlock(rowId, colIndex, blockIndex);
        });

        $(document).on('change', '.row-name-input', function() {
            var rowId = $(this).data('row-id');
            var row = getRow(rowId);
            if (row) row.name = $(this).val();
            notePageHistory();
        });

        // Row background settings
    // Presets rather than a box expecting CSS. The field held the whole padding
    // shorthand and was labelled "e.g. 40px 20px", so the one thing anyone came
    // here for — closing the white seam between two sections — was a guess at a
    // syntax. A single length is space above and below; the sides are the
    // template's business, and a full-width row has none.
    var ROW_SPACING = [
        ['0', 'None'],
        ['20px', 'Small (20px)'],
        ['40px', 'Medium (40px)'],
        ['64px', 'Large (64px)'],
        ['80px', 'Extra large (80px)']
    ];

    function spacingField(id, label, value) {
        value = value || '';
        var known = ROW_SPACING.some(function(p) { return p[0] === value; });
        var custom = value !== '' && !known;

        var options = ['<option value=""' + (value === '' ? ' selected' : '') + '>Template default</option>'];
        ROW_SPACING.forEach(function(p) {
            options.push('<option value="' + p[0] + '"' + (value === p[0] ? ' selected' : '') + '>' + p[1] + '</option>');
        });
        options.push('<option value="__custom"' + (custom ? ' selected' : '') + '>Custom\u2026</option>');

        return '<div class="form-group"><label>' + label + '</label>' +
            '<select class="form-control" id="' + id + '-preset">' + options.join('') + '</select>' +
            '<input type="text" class="form-control mt-1" id="' + id + '" value="' + escHtml(value) + '" ' +
                'placeholder="e.g. 40px, or 40px 20px for the sides too"' + (custom ? '' : ' hidden') + '>' +
            '</div>';
    }

        $(document).on('click', '.row-bg-btn', function() {
            var rowId = $(this).data('row-id');
            var row = getRow(rowId);
            if (!row) return;
            var rowWidth = row.width === 'full' ? 'full' : 'contained';
            var html = '<div class="form-group"><label>Row Width</label>' +
                '<select class="form-control" id="row-width">' +
                '<option value="contained"' + (rowWidth === 'contained' ? ' selected' : '') + '>Contained (template default width)</option>' +
                '<option value="full"' + (rowWidth === 'full' ? ' selected' : '') + '>Full Width (edge to edge)</option>' +
                '</select>' +
                '<small class="form-text text-muted">Templates define their own contained width. Full width spans the viewport.</small>' +
                '</div>' +
                '<div class="form-group"><label>Background Color</label>' +
                '<div class="input-group"><input type="color" class="form-control form-control-color" id="row-bg-color" value="' + escHtml(swatchValue(row.background_color, '#ffffff')) + '" style="width:60px;padding:2px;">' +
                '<input type="text" class="form-control" id="row-bg-color-text" value="' + escHtml(row.background_color || '') + '" placeholder="#hex or empty for none">' +
                '<div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="row-bg-color-clear" title="Clear"><i class="fas fa-times"></i></button></div></div></div>' +
                imageField('row-bg-image', 'Background Image', row.background_image) +
                '<hr>' +
                '<div class="form-group"><label>Text Color</label>' +
                '<div class="input-group"><input type="color" class="form-control form-control-color" id="row-text-color" value="' + escHtml(swatchValue(row.text_color, '#000000')) + '" style="width:60px;padding:2px;">' +
                '<input type="text" class="form-control" id="row-text-color-text" value="' + escHtml(row.text_color || '') + '" placeholder="#hex or empty for default">' +
                '<div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="row-text-color-clear" title="Clear"><i class="fas fa-times"></i></button></div></div></div>' +
                '<div class="row"><div class="col-6"><div class="form-group"><label>Text Alignment</label>' +
                '<select class="form-control" id="row-text-align">' +
                '<option value=""' + (!row.text_alignment ? ' selected' : '') + '>Default</option>' +
                '<option value="left"' + (row.text_alignment === 'left' ? ' selected' : '') + '>Left</option>' +
                '<option value="center"' + (row.text_alignment === 'center' ? ' selected' : '') + '>Center</option>' +
                '<option value="right"' + (row.text_alignment === 'right' ? ' selected' : '') + '>Right</option>' +
                '</select></div></div>' +
                '<div class="col-6">' + spacingField('row-padding', 'Space above &amp; below', row.padding) + '</div></div>';
            $('#block-edit-content').html(html);
            $('#block-edit-modal .modal-title').text('Row Style');
            clearEditorPreviewPane();
            editingRowId = rowId;
            editingColIndex = null;
            editingBlockIndex = null;
            $('#save-block-btn').show();
            $('#row-bg-color').on('input', function() { $('#row-bg-color-text').val($(this).val()); });
            $('#row-bg-color-text').on('input', function() { var v = $(this).val(); if (/^#[0-9a-fA-F]{6}$/.test(v)) $('#row-bg-color').val(v); });
            $('#row-bg-color-clear').on('click', function() { $('#row-bg-color-text').val(''); });
            $('#row-text-color').on('input', function() { $('#row-text-color-text').val($(this).val()); });
            $('#row-text-color-text').on('input', function() { var v = $(this).val(); if (/^#[0-9a-fA-F]{6}$/.test(v)) $('#row-text-color').val(v); });
            $('#row-text-color-clear').on('click', function() { $('#row-text-color-text').val(''); });
            $('#row-padding-preset').on('change', function() {
                var choice = $(this).val();
                if (choice === '__custom') { $('#row-padding').removeAttr('hidden').focus(); return; }
                $('#row-padding').attr('hidden', 'hidden').val(choice);
            });
            $('#block-edit-modal').modal('show');
        });

        $(document).on('click', '#save-block-btn', function() { saveBlock(); });

        $(document).on('click', '.admin-acc-header', function(e) {
            e.stopPropagation();
            var $item = $(this).closest('.admin-acc-item');
            var $body = $item.find('.admin-acc-body');
            var $chevron = $item.find('.admin-acc-chevron');
            var isOpen = $item.hasClass('open');
            if (isOpen) {
                $item.removeClass('open');
                $body.css({'max-height': '0', 'padding': '0 15px'});
                $chevron.css('transform', 'rotate(0deg)');
            } else {
                $item.addClass('open');
                $body.css({'max-height': '2000px', 'padding': '10px 15px'});
                $chevron.css('transform', 'rotate(180deg)');
            }
        });

        $(document).on('input', '#video-url', function() {
            $('#video-preview').html(getVideoPreviewHtml($(this).val()));
        });

        // --- Media Browser event handlers ---
        $(document).on('click', '.media-browser-item', function() {
            var url = $(this).data('url');
            var alt = $(this).data('alt') || '';
            var id = $(this).data('id');

            if (_mediaBrowserMulti) {
                // Multi-select mode: toggle selection
                $(this).toggleClass('selected');
                _mediaBrowserSelected = [];
                $('#media-browser-grid .media-browser-item.selected').each(function() {
                    _mediaBrowserSelected.push({ id: $(this).data('id'), url: $(this).data('url'), alt: $(this).data('alt') || '' });
                });
                var count = _mediaBrowserSelected.length;
                $('#bulk-count').text(count);
                $('#media-browser-add-selected').prop('disabled', count === 0);
                return;
            }

            if (_mediaBrowserCallback) {
                _mediaBrowserCallback({ id: id, url: url, alt: alt });
            }
            $('#media-browser-modal').modal('hide');
        });

        $(document).on('click', '#media-browser-add-selected', function() {
            if (_mediaBrowserMultiCallback && _mediaBrowserSelected.length) {
                _mediaBrowserMultiCallback(_mediaBrowserSelected);
            }
            $('#media-browser-modal').modal('hide');
        });

        $(document).on('click', '.clear-media-field', function() {
            $(this).closest('.input-group').find('input[type="text"]').val('').trigger('change');
        });

        $(document).on('input change', '.media-field-input', function() {
            var val = ($(this).val() || '').trim();
            var $preview = $(this).closest('.form-group').find('.media-field-preview');
            if (val) {
                $preview.find('img').attr('src', val);
                $preview.show();
            } else {
                $preview.hide().find('img').attr('src', '');
            }
        });

        $(document).on('click', '.browse-media-field', function() {
            var $input = $(this).closest('.input-group').find('input[type="text"]');
            var $row = $(this).closest('.gallery-image-row, .carousel-slide-row, .testi-row');
            openMediaBrowser(function(media) {
                $input.val(media.url).trigger('change');
                if (media.alt && $row.length) {
                    var $alt = $row.find('.gal-alt');
                    if ($alt.length && !$alt.val()) $alt.val(media.alt);
                }
            });
        });

        $('#media-browser-modal').on('shown.bs.modal shown.coreui.modal', function() {
            $('.modal-backdrop').last().css('z-index', 1065);
        }).on('hidden.bs.modal hidden.coreui.modal', function() {
            if ($('#block-edit-modal').hasClass('show')) {
                $('body').addClass('modal-open');
            }
        });

        var _browserScrollEl = document.getElementById('media-browser-scroll');
        if (_browserScrollEl) {
            _browserScrollEl.addEventListener('scroll', function() {
                if (this.scrollTop + this.clientHeight >= this.scrollHeight - 200) {
                    loadMediaBrowserPage();
                }
            });
        }

        $('#media-browser-upload-btn').on('click', function() {
            $('#media-browser-file').click();
        });

        $('#media-browser-file').on('change', function() {
            var file = this.files[0];
            if (!file) return;
            var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            var formData = new FormData();
            formData.append('file', file);
            formData.append('size', 20);
            formData.append('width', 4096);
            formData.append('height', 4096);
            $('#media-browser-upload-btn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');
            fetch(getMediaUploadUrl(), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf },
                body: formData
            }).then(function(r) { return r.json(); }).then(function(resp) {
                return fetch(getMediaUrl(), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                    body: JSON.stringify({ media_file: resp.name, title: file.name })
                });
            }).then(function(r) { return r.json(); }).then(function(data) {
                $('#media-browser-upload-btn').prop('disabled', false).html('<i class="fas fa-upload mr-1"></i> Upload');
                $('#media-browser-file').val('');
                if (data.success && data.url) {
                    if (_mediaBrowserCallback) {
                        _mediaBrowserCallback({ url: data.url, alt: '' });
                    }
                    $('#media-browser-modal').modal('hide');
                }
            }).catch(function() {
                $('#media-browser-upload-btn').prop('disabled', false).html('<i class="fas fa-upload mr-1"></i> Upload');
                $('#media-browser-file').val('');
            });
        });

        $('#block-edit-modal').on('hidden.bs.modal hidden.coreui.modal', function() {
            if (editorJsInstance) { try { editorJsInstance.destroy(); } catch(e) {} editorJsInstance = null; }
            // The iframe would otherwise sit there holding a whole rendered
            // page until the next block is opened.
            clearEditorPreviewPane();
            _htmlDoc = null;
            _htmlHasFields = false;
            _htmlHidden = [];
            _htmlMoveUnavailable = false;
            _htmlSelected = null;
            _htmlPartStyles = {};
            _blockHistory = newHistory();
            _blockEditTouched = false;
            _committingBlock = false;
            $('#save-block-btn').show();
        });

        // Auto-slug generation (only if slug not manually edited)
        $('#slug').on('input', function() {
            _slugManuallyEdited = true;
        });

        $('#title').on('input', function() {
            if (_slugManuallyEdited) return;
            var slug = $(this).val().toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '');
            $('#slug').val(slug);
        });

        $(document).on('click', '.vela-undo-btn', function() { runUndo(); });
        $(document).on('click', '.vela-redo-btn', function() { runRedo(); });

        $(document).on('keydown', function(e) {
            if (!(e.ctrlKey || e.metaKey)) return;
            var key = (e.key || '').toLowerCase();
            if (key !== 'z' && key !== 'y') return;

            // A text box keeps the browser's own undo, which works letter by
            // letter and is what someone in the middle of a field means. The
            // wording typed straight into the preview is covered by this too:
            // it lives in another document, so those keystrokes never arrive
            // here, and its native undo raises the same input event an edit
            // does, which puts the wording back through the ordinary path.
            var target = e.target;
            if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA'
                || target.isContentEditable)) return;

            e.preventDefault();
            if (key === 'y' || e.shiftKey) runRedo();
            else runUndo();
        });

        // Form submit: serialize rows to hidden input
        $('#page-form').on('submit', function() {
            $('#rows-json').val(collectData());
        });
    }

    /**
     * Adopt the ids the server assigned to rows and blocks that were new.
     *
     * Only relevant to a save that leaves the editor open: the placeholder ids
     * live on in the page, and saving again with them would create duplicates
     * of everything just written. Rows carry their id in the markup as well as
     * in the model, so both are updated; blocks are addressed by position, so
     * the model alone is enough. Nothing is re-rendered — the wording someone
     * is halfway through typing survives the save.
     */
    function applyIdMap(map) {
        if (!map) return;
        var rowMap   = map.rows   || {};
        var blockMap = map.blocks || {};

        rows.forEach(function(row) {
            var newRowId = rowMap[String(row.id)];
            if (newRowId !== undefined && newRowId !== row.id) {
                // .data() is read straight off these elements by the handlers,
                // and jQuery caches that on first read, so the attribute alone
                // would leave the stale id in play.
                $('[data-row-id="' + row.id + '"]')
                    .attr('data-row-id', newRowId)
                    .data('row-id', newRowId)
                    .data('rowId', newRowId);
                row.id = newRowId;
            }
            row.columns.forEach(function(col) {
                col.blocks.forEach(function(block) {
                    var newBlockId = blockMap[String(block.id)];
                    if (newBlockId !== undefined) block.id = newBlockId;
                });
            });
        });
    }

    // Closing the block dialog is the one way to lose work here without being
    // told: Cancel, the X, Escape and a click on the backdrop all throw away
    // everything typed, and none of it ever reached the page, so undo cannot
    // bring it back either.
    //
    // Bound out here, off document, rather than inside bindFormEvents(): these
    // are delegated, so they need neither the dialog to exist yet nor init() to
    // have finished, and a guard against losing work should not itself be lost
    // to an error raised somewhere earlier in setup.
    $(document)
        .on('hide.bs.modal hide.coreui.modal', '#block-edit-modal', function(e) {
            if (_committingBlock || !_blockEditTouched) return;

            // This close is always cancelled, whatever the answer turns out to
            // be. A native confirm hands focus back to the button that opened
            // it, and asking from inside the close means that button is inside
            // a dialog already on its way out — the browser then blocks the
            // aria-hidden it is about to be marked with, because focus is still
            // in there. Asking after this close has been called off, and
            // closing again once the answer is in, keeps the two apart.
            e.preventDefault();

            var modal = this;
            var message = (window.PageEditorConfig && PageEditorConfig.i18n
                && PageEditorConfig.i18n.discardBlockChanges)
                || 'Discard your changes to this block?';

            setTimeout(function() {
                if (!confirm(message)) return;      // staying, with the work intact

                // Answered: nothing left to warn about, so the second close
                // runs the ordinary path with no question in the middle of it.
                _blockEditTouched = false;
                $(modal).modal('hide');
            }, 0);
        })
        // Bootstrap marks the dialog aria-hidden as it closes. If focus is
        // still on something inside it — the close button that was just
        // clicked — the browser refuses, because hiding a focused element from
        // assistive technology would strand a screen reader inside a dialog
        // that is no longer there. Hand focus back to the page first.
        //
        // Runs after the guard above, and must: answering the confirm hands
        // focus straight back to the button that opened it, so a blur taken
        // before the question is undone by the time it is answered.
        .on('hide.bs.modal hide.coreui.modal', '#block-edit-modal', function(e) {
            // Staying open — the caret belongs wherever the work is.
            if (e.isDefaultPrevented()) return;

            var modal = this;

            // Deferred by a tick, and it has to be. While this event is still
            // running the dialog's focus trap is live: it answers any focus
            // landing outside by pulling it back onto the dialog itself, so a
            // blur taken here reads as "focus left" and gets undone — the
            // browser then reports the dialog, rather than the button, as the
            // thing being hidden out from under the focus. Bootstrap drops the
            // trap the moment this event returns, and only marks the dialog
            // aria-hidden after the fade, which leaves this tick free.
            setTimeout(function() {
                var focused = document.activeElement;
                if (focused && modal.contains(focused) && typeof focused.blur === 'function') {
                    focused.blur();
                }
            }, 0);
        })
        // A fresh dialog starts clean, and every close — kept or discarded —
        // ends the commit it was in.
        .on('shown.bs.modal shown.coreui.modal', '#block-edit-modal', function() {
            _blockEditTouched = false;
            _committingBlock = false;
        })
        .on('input change', '#block-edit-content', function() {
            _blockEditTouched = true;
        });

    // Expose public API
    window.PageEditor.init = init;
    window.PageEditor.collect = collectData;
    window.PageEditor.applyIdMap = applyIdMap;
    // Open dialog with work in it — not yet part of the page, so collect()
    // cannot see it and whatever guards the page needs to ask.
    window.PageEditor.blockEditorDirty = function() {
        return _blockEditTouched && $('#block-edit-modal').hasClass('show');
    };

})(jQuery);
