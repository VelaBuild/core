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
    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
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
        _mediaBrowserCallback = callback;
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
    var _htmlPickMode = false;    // clicking the preview hides what you click
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
    function upgradeImportedBlock(doc) {
        var wrapper = doc.querySelector('[data-vela-block]')
            || doc.querySelector('[class*="vela-import-"]');
        if (!wrapper) return doc;

        if (!wrapper.hasAttribute('data-vela-block')) {
            wrapper.setAttribute('data-vela-block', 'b' + Math.abs(hashString(wrapper.innerHTML)).toString(16).slice(0, 10));
        }

        if (!doc.querySelector('[data-vela-grid]')) {
            var gridIndex = 0;
            Array.prototype.forEach.call(wrapper.querySelectorAll('*'), function(el) {
                var kids = el.children;
                if (kids.length < 2) return;
                var signature = null;
                for (var i = 0; i < kids.length; i++) {
                    var current = kids[i].tagName + '|' + Array.prototype.slice.call(kids[i].classList).sort().slice(0, 4).join(' ');
                    if (signature === null) signature = current;
                    else if (signature !== current) return;
                }
                if (!signature) return;
                el.setAttribute('data-vela-grid', 'g' + (++gridIndex));
                el.setAttribute('data-vela-grid-count', String(kids.length));
            });
        }

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

                if (!kinds.length) return;
                el.setAttribute('data-vela-field', 'f' + (++fieldIndex));
                el.setAttribute('data-vela-field-kind', kinds.join(' '));
            });
        }

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
        return new DOMParser().parseFromString('<div id="vela-root">' + (html || '') + '</div>', 'text/html');
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
        return 'Text';
    }

    function renderImportedFields(doc) {
        var els = fieldElements(doc);
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

            if (kinds.indexOf('link') > -1) {
                out += '<div class="input-group input-group-sm mt-1">' +
                    '<div class="input-group-prepend"><span class="input-group-text"><i class="fas fa-link"></i></span></div>' +
                    '<input type="text" class="form-control vela-field-href" value="' + escHtml(el.getAttribute('href') || '') + '" placeholder="/contact-us">' +
                    '</div>';
            }

            out += '</div>';
        });

        return out + '</div>';
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
            if ($text.length) setFieldText(el, $text.val());

            var $href = $row.find('.vela-field-href');
            if ($href.length) el.setAttribute('href', $href.val());

            var $placeholder = $row.find('.vela-field-placeholder');
            if ($placeholder.length) {
                if ($placeholder.val()) el.setAttribute('placeholder', $placeholder.val());
                else el.removeAttribute('placeholder');
            }
        });

        return doc;
    }

    function refreshImportedPreview() {
        var $frame = $('#vela-html-preview');
        if (!$frame.length || !_htmlDoc) return;

        var html = serializeBlockHtml(applyDesign(applyImportedFields(_htmlDoc), collectDesign()));
        $('#html-content').val(html);

        // While picking, the preview reports what was clicked as a path from
        // the wrapper, and outlines whatever is under the pointer so it is
        // clear what would go.
        var picker = _htmlPickMode ? '<style>*{cursor:crosshair!important}' +
                '[data-vela-block] *:hover{outline:2px solid #f0ad4e!important;outline-offset:-2px!important}</style>' +
            '<script>document.addEventListener("click",function(e){' +
                'e.preventDefault();e.stopPropagation();' +
                'var root=document.querySelector("[data-vela-block]");' +
                'if(!root)return;' +
                'var node=e.target,path=[];' +
                'while(node&&node!==root){' +
                    'var parent=node.parentElement;if(!parent)return;' +
                    'path.unshift(Array.prototype.indexOf.call(parent.children,node));node=parent;}' +
                'if(node!==root)return;' +
                'parent.postMessage({velaPick:path.join("/")},"*");' +
            '},true);<\/script>' : '';

        $frame[0].srcdoc = '<!doctype html><html><head><meta charset="utf-8">' +
            '<style>body{margin:0}' + pageCustomCss() + '</style>' + picker + '</head><body>' + html + '</body></html>';
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

        // Parts the user chose not to show. Ids only — anything else here
        // would end up inside a CSS selector.
        var hidden = (design.hidden || []).filter(function(id) {
            return /^[fp]\d+$/.test(id);
        });
        if (hidden.length) clean.hidden = hidden.slice(0, 200);

        return clean;
    }

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

        (grids || []).forEach(function(g) {
            var columns = parseInt((design.grids || {})[g.id], 10);
            if (!columns || columns < 1 || columns > 8) return;
            css += sel + ' [data-vela-grid="' + g.id + '"]{display:grid !important;' +
                'grid-template-columns:repeat(' + columns + ',minmax(0,1fr)) !important}';
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
        var gridRows = grids.filter(function(g) {
            return g.count >= 2 && g.count <= 8 && g.label;
        }).slice(0, 6).map(function(g) {
            var current = (d.grids || {})[g.id] || '';
            var options = ['<option value="">Keep the original (' + g.count + ')</option>'];
            for (var n = 1; n <= 6; n++) {
                options.push('<option value="' + n + '"' + (String(current) === String(n) ? ' selected' : '') + '>' + n + ' per row</option>');
            }
            return '<div class="form-group col-md-6 mb-2">' +
                '<label class="mb-1 text-truncate d-block" title="' + escHtml(g.label || g.id) + '" ' +
                'style="font-size:.75rem;color:#6c757d;">Columns — ' + escHtml(g.label || g.id) + '</label>' +
                '<select class="form-control form-control-sm vela-design" data-design-grid="' + escHtml(g.id) + '">' + options.join('') + '</select></div>';
        }).join('');

        return '<details class="mb-3" open><summary style="cursor:pointer;font-weight:500;">' +
                '<i class="fas fa-palette mr-1"></i> Design</summary>' +
            '<div class="form-row mt-2">' +
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
                gridRows +
            '</div>' +
            renderHiddenParts(doc) +
            '<button type="button" class="btn btn-link btn-sm px-0" id="vela-design-reset">Reset design to the original</button>' +
            '</details>';
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

        return '<div class="mt-2 mb-2">' +
            '<button type="button" class="btn btn-sm ' + (_htmlPickMode ? 'btn-warning' : 'btn-outline-secondary') + '" id="vela-pick-mode">' +
                '<i class="fas fa-hand-pointer mr-1"></i>' + (_htmlPickMode ? 'Done choosing' : 'Click the preview to leave parts out') +
            '</button>' +
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
    function markPartAtPath(doc, path) {
        var node = doc.querySelector('[data-vela-block]');
        if (!node) return null;

        var steps = String(path).split('/').filter(function(step) { return step !== ''; });
        for (var i = 0; i < steps.length; i++) {
            node = node.children[parseInt(steps[i], 10)];
            if (!node) return null;
        }

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

    function collectDesign() {
        var design = { grids: {}, hidden: _htmlHidden.slice() };
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
        if (!Object.keys(design.grids).length) delete design.grids;
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
    function redrawImportedEditor() {
        if (!_htmlDoc) return;

        applyDesign(applyImportedFields(_htmlDoc), collectDesign());

        var $panel = $('#block-edit-content');
        var scroll = $panel.scrollTop();
        var design = renderDesignPanel(_htmlDoc);
        var fields = renderImportedFields(_htmlDoc);

        $panel.find('details').first().replaceWith(design);
        if (fields) {
            $panel.find('#vela-field-list').replaceWith(fields);
        }
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
            _htmlPickMode = false;
            _htmlHidden = (readDesign(_htmlDoc.querySelector('[data-vela-block]')).hidden || []).slice();
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
                    '<i class="fas fa-wand-magic-sparkles mr-1"></i> Imported section — change its wording, pictures, links and design below. ' +
                    'Anything left blank keeps how the original looked.' +
                '</div>' +
                '<iframe id="vela-html-preview" style="width:100%;height:300px;border:1px solid #e9ecef;border-radius:4px;background:#fff;margin-bottom:12px;"></iframe>' +
                renderDesignPanel(_htmlDoc) +
                (fields || '<div id="vela-field-list"></div><div class="alert alert-light border py-2"><small>' +
                    'No editable wording was found in this section — its text sits inside markup this form cannot take apart. ' +
                    'Change it under "Edit the HTML directly" below, or restyle it with the design controls above.' +
                    '</small></div>') +
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

            $('#vela-field-list').on('input.velaImported', '.vela-field-text, .vela-field-href, .vela-field-src, .vela-field-alt, .vela-field-placeholder', function() {
                if ($(this).hasClass('vela-field-src')) {
                    $(this).closest('[data-field]').find('.vela-field-thumb').attr('src', $(this).val());
                }
                scheduleImportedPreview();
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

            $('#block-edit-content').on('click.velaImported', '#vela-pick-mode', function() {
                _htmlPickMode = !_htmlPickMode;
                redrawImportedEditor();
            });

            $(window).on('message.velaImported', function(event) {
                var data = event.originalEvent && event.originalEvent.data;
                if (!data || typeof data.velaPick !== 'string' || !_htmlPickMode || !_htmlDoc) return;
                var id = markPartAtPath(_htmlDoc, data.velaPick);
                if (id) toggleHiddenPart(id);
            });

            $('#block-edit-content').on('click.velaImported', '.vela-design-clear', function() {
                $('[data-design="' + $(this).data('clear-for') + '"]').val('');
                refreshImportedPreview();
            });

            $('#block-edit-content').on('click.velaImported', '#vela-design-reset', function() {
                $('.vela-design').val('');
                $('.vela-design-custom').val('').attr('hidden', 'hidden');
                _htmlHidden = [];
                _htmlPickMode = false;
                redrawImportedEditor();
            });

            $('#vela-field-list').on('click.velaImported', '.vela-field-browse', function() {
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
                '<div class="form-group col-md-4"><label>Min Height</label><input type="text" class="form-control" id="hero-height" value="' + escHtml(s.min_height || '80vh') + '" placeholder="80vh"></div></div>';
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
            '<div class="input-group"><input type="color" class="form-control form-control-color" id="block-bg-color" value="' + escHtml(block.background_color || '#ffffff') + '" style="width:60px;padding:2px;">' +
            '<input type="text" class="form-control" id="block-bg-color-text" value="' + escHtml(block.background_color || '') + '" placeholder="#hex or empty for none">' +
            '<div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="block-bg-color-clear" title="Clear"><i class="fas fa-times"></i></button></div></div></div>' +
            '<div class="form-group"><label>Background Image URL</label>' +
            '<input type="text" class="form-control" id="block-bg-image" value="' + escHtml(block.background_image || '') + '" placeholder="https://...">' +
            '</div>' +
            '<div class="form-group"><label>Text Color</label>' +
            '<div class="input-group"><input type="color" class="form-control form-control-color" id="block-text-color" value="' + escHtml(block.text_color || '#000000') + '" style="width:60px;padding:2px;">' +
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
        });

        // Row background settings
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
                '<div class="input-group"><input type="color" class="form-control form-control-color" id="row-bg-color" value="' + escHtml(row.background_color || '#ffffff') + '" style="width:60px;padding:2px;">' +
                '<input type="text" class="form-control" id="row-bg-color-text" value="' + escHtml(row.background_color || '') + '" placeholder="#hex or empty for none">' +
                '<div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="row-bg-color-clear" title="Clear"><i class="fas fa-times"></i></button></div></div></div>' +
                '<div class="form-group"><label>Background Image URL</label>' +
                '<input type="text" class="form-control" id="row-bg-image" value="' + escHtml(row.background_image || '') + '" placeholder="https://...">' +
                (row.background_image ? '<div class="mt-2"><img src="' + escHtml(row.background_image) + '" style="max-height:80px;border-radius:4px;"></div>' : '') +
                '</div>' +
                '<hr>' +
                '<div class="form-group"><label>Text Color</label>' +
                '<div class="input-group"><input type="color" class="form-control form-control-color" id="row-text-color" value="' + escHtml(row.text_color || '#000000') + '" style="width:60px;padding:2px;">' +
                '<input type="text" class="form-control" id="row-text-color-text" value="' + escHtml(row.text_color || '') + '" placeholder="#hex or empty for default">' +
                '<div class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="row-text-color-clear" title="Clear"><i class="fas fa-times"></i></button></div></div></div>' +
                '<div class="row"><div class="col-6"><div class="form-group"><label>Text Alignment</label>' +
                '<select class="form-control" id="row-text-align">' +
                '<option value=""' + (!row.text_alignment ? ' selected' : '') + '>Default</option>' +
                '<option value="left"' + (row.text_alignment === 'left' ? ' selected' : '') + '>Left</option>' +
                '<option value="center"' + (row.text_alignment === 'center' ? ' selected' : '') + '>Center</option>' +
                '<option value="right"' + (row.text_alignment === 'right' ? ' selected' : '') + '>Right</option>' +
                '</select></div></div>' +
                '<div class="col-6"><div class="form-group"><label>Padding</label>' +
                '<input type="text" class="form-control" id="row-padding" value="' + escHtml(row.padding || '') + '" placeholder="e.g. 40px 20px">' +
                '</div></div></div>';
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

        $(document).on('click', '.browse-media-field', function() {
            var $input = $(this).closest('.input-group').find('input[type="text"]');
            var $row = $(this).closest('.gallery-image-row, .carousel-slide-row, .testi-row');
            openMediaBrowser(function(media) {
                $input.val(media.url);
                if (media.alt && $row.length) {
                    var $alt = $row.find('.gal-alt');
                    if ($alt.length && !$alt.val()) $alt.val(media.alt);
                }
            });
        });

        $('#media-browser-modal').on('shown.bs.modal', function() {
            $('.modal-backdrop').last().css('z-index', 1065);
        }).on('hidden.bs.modal', function() {
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

        // Bootstrap marks the dialog aria-hidden as it closes. If focus is
        // still on something inside it — the close button the user just
        // clicked — the browser refuses, because hiding a focused element from
        // assistive technology would strand a screen reader inside a dialog
        // that is no longer there. Hand focus back to the page first.
        $('#block-edit-modal').on('hide.bs.modal', function() {
            var focused = document.activeElement;
            if (focused && this.contains(focused) && typeof focused.blur === 'function') {
                focused.blur();
            }
        });

        $('#block-edit-modal').on('hidden.bs.modal', function() {
            if (editorJsInstance) { try { editorJsInstance.destroy(); } catch(e) {} editorJsInstance = null; }
            // The iframe would otherwise sit there holding a whole rendered
            // page until the next block is opened.
            clearEditorPreviewPane();
            _htmlDoc = null;
            _htmlHasFields = false;
            _htmlHidden = [];
            _htmlPickMode = false;
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

        // Form submit: serialize rows to hidden input
        $('#page-form').on('submit', function() {
            $('#rows-json').val(collectData());
        });
    }

    // Expose public API
    window.PageEditor.init = init;

})(jQuery);
