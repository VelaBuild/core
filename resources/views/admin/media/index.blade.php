@extends('vela::layouts.admin')

@section('breadcrumb', trans('vela::media.library_title'))
@section('styles')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<style>
    .media-card { cursor: pointer; margin-bottom: 15px; transition: box-shadow 0.2s; }
    .media-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    .media-card img { width: 100%; height: 180px; object-fit: cover; }
    .media-card .card-body { padding: 8px; }
    .media-card .card-body small { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .media-card.selected { border: 2px solid var(--vela-teal-400); }
    .view-toggle .btn.active { background-color: var(--vela-teal-500); color: #fff; border-color: var(--vela-teal-500); }
    #crop-image { max-width: 100%; display: block; }
</style>
@endsection

@section('content')

{{-- Filter Bar --}}
<div class="vela-panel" style="padding: 0; overflow: hidden; margin-bottom: var(--v-space-4);">
    <div style="padding: var(--v-space-4); display: flex; gap: var(--v-space-3); align-items: center; border-bottom: 1px solid var(--v-border); flex-wrap: wrap;">
        <div class="vela-search-bar" style="max-width: 280px;">
            <span class="search-ico"><i class="fas fa-search"></i></span>
            <input placeholder="{{ trans('vela::media.search_files') }}..." id="media-search-input">
        </div>
        <select id="type-filter" class="form-control form-control-sm" style="width: auto; max-width: 160px; height: 34px; border-radius: var(--v-r-full); font-size: var(--v-text-sm);">
            <option value="">{{ trans('vela::media.all_types') }}</option>
            <option value="image">{{ trans('vela::media.all_images') }}</option>
            <option value="application/pdf">{{ trans('vela::media.all_documents') }} (PDF)</option>
        </select>
        <select id="collection-filter" class="form-control form-control-sm" style="width: auto; max-width: 160px; height: 34px; border-radius: var(--v-r-full); font-size: var(--v-text-sm);">
            <option value="">{{ trans('vela::global.all_collections') }}</option>
        </select>
        <select id="model-filter" class="form-control form-control-sm" style="width: auto; max-width: 160px; height: 34px; border-radius: var(--v-r-full); font-size: var(--v-text-sm);">
            <option value="">{{ trans('vela::global.owner_type') }}</option>
            <option value="VelaBuild\Core\Models\Content">{{ trans('vela::media.content_owner') }}</option>
            <option value="VelaBuild\Core\Models\Page">{{ trans('vela::media.page_owner') }}</option>
            <option value="VelaBuild\Core\Models\Category">{{ trans('vela::media.category_owner') }}</option>
            <option value="VelaBuild\Core\Models\VelaUser">{{ trans('vela::media.user_owner') }}</option>
            <option value="VelaBuild\Core\Models\MediaItem">{{ trans('vela::media.standalone_owner') }}</option>
        </select>
        <span id="media-selected-count" class="vela-badge vela-badge-accent" style="margin-left: auto; display: none;"></span>
        @if($hasAiProvider)
        <button class="vela-btn vela-btn-ghost vela-btn-sm" id="ai-generate-btn">
            <i class="fas fa-magic mr-1"></i> {{ trans('vela::global.generate') }}
        </button>
        @endif
        <button class="vela-btn vela-btn-accent vela-btn-sm" id="upload-btn">
            <i class="fas fa-cloud-upload-alt mr-1"></i> {{ trans('vela::global.upload') }}
        </button>
        <div class="btn-group view-toggle" role="group">
            <button type="button" class="btn btn-outline-secondary btn-sm active" data-view="grid" title="{{ trans('vela::global.grid_view') }}">
                <i class="fas fa-th"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-view="list" title="{{ trans('vela::global.list_view') }}">
                <i class="fas fa-list"></i>
            </button>
        </div>
        <button class="vela-btn vela-btn-sm" id="bulk-delete-btn" style="display:none; background: var(--vela-danger-bg); color: var(--vela-danger); border: 1px solid var(--vela-danger);">
            <i class="fas fa-trash mr-1"></i> {{ trans('vela::global.delete_selected') }}
        </button>
    </div>
</div>

{{-- Upload Zone --}}
<div class="vela-panel mb-3" id="upload-zone" style="display:none;">
    <div class="needsclick dropzone" id="media-library-dropzone"></div>
</div>

{{-- Grid View Container --}}
<div id="media-grid" class="row"></div>
<div id="grid-loading" class="text-center py-4" style="display:none;">
    <i class="fas fa-spinner fa-spin fa-2x" style="color: var(--v-accent);"></i>
</div>
<div id="grid-empty" class="text-center py-5" style="display:none;">
    <i class="fas fa-images fa-4x mb-3 d-block" style="color: var(--v-fg-subtle);"></i>
    <p style="color: var(--v-fg-muted);">{{ trans('vela::media.no_media_found') }}</p>
</div>

<!-- List View Container (hidden by default) -->
<div id="media-list" style="display:none;">
    <div class="card">
        <div class="card-body">
            <table id="media-datatable" class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all"></th>
                        <th>{{ trans('vela::global.preview') }}</th>
                        <th>{{ trans('vela::global.name') }}</th>
                        <th>{{ trans('vela::global.collection') }}</th>
                        <th>{{ trans('vela::global.type') }}</th>
                        <th>{{ trans('vela::global.size') }}</th>
                        <th>{{ trans('vela::global.owner') }}</th>
                        <th>{{ trans('vela::global.date') }}</th>
                        <th>{{ trans('vela::global.actions') }}</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<!-- Detail/Preview Modal -->
<div class="modal fade" id="media-detail-modal" tabindex="-1" role="dialog" aria-labelledby="mediaDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mediaDetailModalLabel">{{ trans('vela::media.media_details') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-7">
                        <img id="detail-image" src="" class="img-fluid" alt="">
                    </div>
                    <div class="col-md-5">
                        <dl>
                            <dt>{{ trans('vela::global.filename') }}</dt>
                            <dd id="detail-filename"></dd>
                            <dt>{{ trans('vela::global.dimensions') }}</dt>
                            <dd id="detail-dimensions"></dd>
                            <dt>{{ trans('vela::global.file_size') }}</dt>
                            <dd id="detail-size"></dd>
                            <dt>{{ trans('vela::global.collection') }}</dt>
                            <dd id="detail-collection"></dd>
                            <dt>{{ trans('vela::global.uploaded') }}</dt>
                            <dd id="detail-date"></dd>
                        </dl>

                        <div class="form-group">
                            <label for="detail-title">{{ trans('vela::global.title') }}</label>
                            <input type="text" class="form-control form-control-sm" id="detail-title">
                        </div>
                        <div class="form-group">
                            <label for="detail-alt-text">{{ trans('vela::global.alt_text') }}</label>
                            <input type="text" class="form-control form-control-sm" id="detail-alt-text">
                        </div>

                        <h6>{{ trans('vela::global.used_in') }}</h6>
                        <ul id="detail-used-in" class="pl-3"></ul>

                        <div class="mt-3 d-flex flex-wrap" style="gap:4px;">
                            <button class="btn btn-sm btn-primary" id="detail-save-meta">{{ trans('vela::global.save') }}</button>
                            <button class="btn btn-sm btn-warning" id="detail-replace-btn">{{ trans('vela::global.replace') }}</button>
                            <button class="btn btn-sm btn-info" id="detail-crop-btn">{{ trans('vela::global.crop') }}</button>
                            <button class="btn btn-sm btn-secondary" id="detail-regen-cache">{{ trans('vela::media.regenerate_cache') }}</button>
                            <button class="btn btn-sm btn-outline-secondary" id="detail-clear-cache">{{ trans('vela::media.clear_cache') }}</button>
                            <button class="btn btn-sm btn-danger" id="detail-delete-btn">{{ trans('vela::global.delete') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Replace Modal -->
<div class="modal fade" id="replace-modal" tabindex="-1" role="dialog" aria-labelledby="replaceModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="replaceModalLabel">{{ trans('vela::media.replace_image') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="replace-media-id">
                <div class="needsclick dropzone" id="replace-dropzone"></div>
                <p class="text-warning mt-2">
                    <i class="fas fa-exclamation-triangle"></i> {{ trans('vela::media.replace_update_refs') }}
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('vela::global.cancel') }}</button>
                <button type="button" class="btn btn-warning" id="confirm-replace-btn">{{ trans('vela::global.replace') }}</button>
            </div>
        </div>
    </div>
</div>

<!-- Crop Modal -->
<div class="modal fade" id="crop-modal" tabindex="-1" role="dialog" aria-labelledby="cropModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cropModalLabel">{{ trans('vela::media.crop_image') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="mb-2 d-flex align-items-center flex-wrap" style="gap:4px;">
                    <span class="mr-2 font-weight-bold">{{ trans('vela::media.aspect_ratio') }}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary crop-aspect-btn" data-ratio="free">{{ trans('vela::media.free') }}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary crop-aspect-btn" data-ratio="1:1">1:1</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary crop-aspect-btn" data-ratio="16:9">16:9</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary crop-aspect-btn" data-ratio="4:3">4:3</button>
                    <span class="ml-3 mr-2 font-weight-bold">{{ trans('vela::media.rotate') }}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="crop-rotate-left">
                        <i class="fas fa-undo"></i> -90°
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="crop-rotate-right">
                        <i class="fas fa-redo"></i> +90°
                    </button>
                </div>
                <img id="crop-image" src="" alt="">
                <input type="hidden" id="crop-media-id">
                <input type="hidden" id="crop-updated-at">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('vela::global.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="crop-save-btn">{{ trans('vela::media.save_crop') }}</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="delete-confirm-modal" tabindex="-1" role="dialog" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteConfirmModalLabel">{{ trans('vela::media.delete_media') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p>{!! trans('vela::global.image_used_in', ['count' => '<span id="delete-usage-count">0</span>']) !!}</p>
                <ul id="delete-usage-list" class="pl-3"></ul>
                <p class="text-danger">{{ trans('vela::media.delete_confirm') }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('vela::global.cancel') }}</button>
                <button type="button" class="btn btn-danger" id="confirm-delete-btn">{{ trans('vela::global.delete') }}</button>
            </div>
        </div>
    </div>
</div>

<!-- AI Generation Modal -->
<div class="modal fade" id="ai-generate-modal" tabindex="-1" role="dialog" aria-labelledby="aiGenerateModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="aiGenerateModalLabel">{{ trans('vela::media.generate_ai_image') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="ai-prompt">{{ trans('vela::media.describe_image') }}</label>
                    <input type="text" class="form-control" id="ai-prompt" placeholder="{{ trans('vela::media.describe_image_placeholder') }}">
                </div>
                <div id="ai-loading" style="display:none;" class="text-center py-2">
                    <i class="fas fa-spinner fa-spin"></i> {{ trans('vela::global.generating') }}
                </div>
                <div id="ai-result"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('vela::global.cancel') }}</button>
                <button type="button" class="btn btn-info" id="ai-generate-submit">{{ trans('vela::global.generate') }}</button>
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
<script>
(function($) {
    const translations = {
        saved: '{{ trans('vela::global.saved') }}',
        replacementFailed: '{{ trans('vela::media.replacement_failed') }}',
        uploadFileFirst: '{{ trans('vela::media.upload_file_first') }}',
        deleteSelectedConfirm: '{{ trans('vela::media.delete_selected_confirm') }}',
        errorGenerating: '{{ trans('vela::media.error_generating') }}',
        imageReplaced: '{{ trans('vela::media.image_replaced') }}',
        referencesUpdated: '{{ trans('vela::media.references_updated') }}',
    };

    const CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const baseUrl = '{{ route("vela.admin.media.index") }}';
    let currentView = 'grid';
    let gridCursor = null;
    let gridLoading = false;
    let gridDone = false;
    let cropper = null;
    let currentMediaId = null;

    // --- Dropzone Setup ---
    Dropzone.autoDiscover = false;
    const uploadDropzone = new Dropzone('#media-library-dropzone', {
        url: '{{ route("vela.admin.media.storeMedia") }}',
        maxFilesize: 20,
        acceptedFiles: '.jpeg,.jpg,.png,.gif,.webp,.svg',
        headers: { 'X-CSRF-TOKEN': CSRF },
        params: { size: 20, width: 4096, height: 4096 },
        success: function(file, response) {
            fetch(baseUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ media_file: response.name, title: file.name })
            }).then(r => r.json()).then(data => {
                if (data.success) { resetGrid(); loadGridPage(); }
            });
        }
    });
    $('#upload-btn').on('click', function() { $('#upload-zone').toggle(); });

    // --- View Toggle ---
    $('.view-toggle .btn').on('click', function() {
        currentView = $(this).data('view');
        $('.view-toggle .btn').removeClass('active');
        $(this).addClass('active');
        if (currentView === 'grid') {
            $('#media-list').hide();
            $('#media-grid').show();
            $('#grid-loading').show();
            $('#grid-empty').show();
            resetGrid();
            loadGridPage();
        } else {
            $('#media-grid').hide();
            $('#grid-loading').hide();
            $('#grid-empty').hide();
            $('#media-list').show();
            initDataTable();
        }
    });

    // --- Grid Infinite Scroll ---
    function loadGridPage() {
        if (gridLoading || gridDone) return;
        gridLoading = true;
        $('#grid-loading').show();
        let url = baseUrl + '?per_page=36';
        if (gridCursor) url += '&cursor=' + gridCursor;
        url += '&collection=' + ($('#collection-filter').val() || '');
        url += '&model_type=' + ($('#model-filter').val() || '');
        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(data => {
                if (data.data.length === 0 && !gridCursor) { $('#grid-empty').show(); }
                data.data.forEach(item => {
                    const col = $('<div class="col-xl-2 col-lg-3 col-md-4 col-sm-6 mb-3">');
                    const card = $('<div class="card media-card">').attr('data-id', item.id);
                    card.append($('<img>').attr('src', item.preview || item.url).attr('alt', item.file_name).attr('loading', 'lazy'));
                    const body = $('<div class="card-body">');
                    body.append($('<small>').attr('title', item.file_name).text(item.file_name));
                    body.append($('<small class="text-muted">').text(formatBytes(item.size)));
                    card.append(body);
                    col.append(card);
                    $('#media-grid').append(col);
                });
                gridCursor = data.next_cursor;
                if (!data.next_cursor) gridDone = true;
                gridLoading = false;
                $('#grid-loading').hide();
            });
    }

    function resetGrid() {
        gridCursor = null;
        gridDone = false;
        $('#media-grid').empty();
        $('#grid-empty').hide();
    }

    const scrollSentinel = document.getElementById('grid-loading');
    const observer = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting && currentView === 'grid') loadGridPage();
    }, { rootMargin: '200px' });
    observer.observe(scrollSentinel);

    loadGridPage();

    // --- DataTable for List View ---
    let dtInitialized = false;
    function initDataTable() {
        if (dtInitialized) { $('#media-datatable').DataTable().ajax.reload(); return; }
        dtInitialized = true;
        $('#media-datatable').DataTable({
            processing: true,
            serverSide: false,
            ajax: {
                url: baseUrl,
                dataSrc: 'data',
                data: function(d) {
                    d.collection = $('#collection-filter').val();
                    d.model_type = $('#model-filter').val();
                    d.per_page = 500;
                }
            },
            columns: [
                { data: null, orderable: false, render: (data) => '<input type="checkbox" value="' + data.id + '" class="media-checkbox">' },
                { data: null, orderable: false, render: (data) => '<img src="' + (data.thumb || data.url) + '" style="width:50px;height:50px;object-fit:cover;">' },
                { data: 'file_name' },
                { data: 'collection_name' },
                { data: 'mime_type' },
                { data: 'size', render: formatBytes },
                { data: 'model_type', render: (d) => d ? d.split('\\').pop() : 'Unknown' },
                { data: 'created_at' },
                { data: null, orderable: false, render: (data) => '<button class="btn btn-xs btn-info media-detail-btn" data-id="' + data.id + '"><i class="fas fa-eye"></i></button>' }
            ],
            order: [[7, 'desc']],
            pageLength: 50
        });
    }

    // --- Click handlers ---
    $(document).on('click', '.media-card', function() { openDetail($(this).data('id')); });
    $(document).on('click', '.media-detail-btn', function() { openDetail($(this).data('id')); });

    function openDetail(mediaId) {
        currentMediaId = mediaId;
        fetch(baseUrl + '/' + mediaId, { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF } })
            .then(r => r.json())
            .then(data => {
                $('#detail-image').attr('src', data.url);
                $('#detail-filename').html($('<a>').attr('href', data.url).attr('target', '_blank').text(data.file_name));
                $('#detail-dimensions').text(data.dimensions || 'Unknown');
                $('#detail-size').text(formatBytes(data.size));
                $('#detail-collection').text(data.collection_name);
                $('#detail-date').html($('<span>').attr('title', data.created_at_exact).text(data.created_at));
                $('#detail-title').val(data.title || '');
                $('#detail-alt-text').val(data.alt_text || '');
                $('#detail-used-in').empty();
                (data.used_in || []).forEach(ref => {
                    const li = $('<li>');
                    if (ref.edit_url) {
                        li.append($('<a>').attr('href', ref.edit_url).text(ref.label));
                    } else {
                        li.append(document.createTextNode(ref.label || ''));
                    }
                    li.append(document.createTextNode(' (' + (ref.type || '') + ')'));
                    if (ref.deleted) li.append(' <span class="badge badge-secondary">deleted</span>');
                    $('#detail-used-in').append(li);
                });
                $('#crop-modal').data('updated-at', data.updated_at);
                $('#media-detail-modal').modal('show');
            });
    }

    // --- Save Meta ---
    $('#detail-save-meta').on('click', function() {
        fetch(baseUrl + '/' + currentMediaId + '/meta', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ title: $('#detail-title').val(), alt_text: $('#detail-alt-text').val() })
        }).then(r => r.json()).then(data => { if (data.success) alert(translations.saved); });
    });

    // --- Filter Changes ---
    $('#type-filter, #collection-filter, #model-filter').on('change', function() {
        if (currentView === 'grid') { resetGrid(); loadGridPage(); }
        else if (dtInitialized) { $('#media-datatable').DataTable().ajax.reload(); }
    });

    // --- Helpers ---
    function formatBytes(bytes) {
        if (!bytes) return '0 B';
        const k = 1024, sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return (bytes / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i];
    }

    // --- Replace ---
    let replaceDropzone = null;
    let replaceTempFile = null;
    $('#detail-replace-btn').on('click', function() {
        $('#replace-modal').data('media-id', currentMediaId);
        $('#media-detail-modal').modal('hide');
        $('#replace-modal').modal('show');
    });
    $('#replace-modal').on('shown.bs.modal', function() {
        if (replaceDropzone) { replaceDropzone.destroy(); }
        replaceTempFile = null;
        replaceDropzone = new Dropzone('#replace-dropzone', {
            url: '{{ route("vela.admin.media.storeMedia") }}',
            maxFilesize: 20,
            maxFiles: 1,
            acceptedFiles: '.jpeg,.jpg,.png,.gif,.webp,.svg',
            headers: { 'X-CSRF-TOKEN': CSRF },
            params: { size: 20, width: 4096, height: 4096 },
            success: function(file, response) { replaceTempFile = response.name; }
        });
    });
    $('#confirm-replace-btn').on('click', function() {
        if (!replaceTempFile) { alert(translations.uploadFileFirst); return; }
        const mediaId = $('#replace-modal').data('media-id');
        fetch(baseUrl + '/' + mediaId + '/replace', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ new_file: replaceTempFile })
        }).then(r => r.json()).then(data => {
            if (data.success) {
                $('#replace-modal').modal('hide');
                resetGrid(); loadGridPage();
                alert(translations.imageReplaced + ' ' + translations.referencesUpdated.replace(':count', data.affected_rows));
            } else { alert(data.message || translations.replacementFailed); }
        });
    });

    // --- Crop ---
    $('#detail-crop-btn').on('click', function() {
        $('#media-detail-modal').modal('hide');
        const img = document.getElementById('crop-image');
        img.src = $('#detail-image').attr('src');
        $('#crop-modal').data('media-id', currentMediaId).modal('show');
        $('#crop-modal').one('shown.bs.modal', function() {
            if (cropper) cropper.destroy();
            cropper = new Cropper(img, { viewMode: 1, autoCropArea: 0.8 });
        });
    });
    $(document).on('click', '.crop-aspect-btn', function() {
        const ratio = $(this).data('ratio');
        if (ratio === 'free') cropper.setAspectRatio(NaN);
        else { const parts = ratio.split(':'); cropper.setAspectRatio(parts[0]/parts[1]); }
        $('.crop-aspect-btn').removeClass('active');
        $(this).addClass('active');
    });
    $(document).on('click', '.crop-rotate-btn', function() {
        cropper.rotate($(this).data('degree'));
    });
    $('#crop-save-btn').on('click', function() {
        const data = cropper.getData(true);
        fetch(baseUrl + '/' + $('#crop-modal').data('media-id') + '/crop', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({
                x: data.x, y: data.y, width: data.width, height: data.height, rotate: data.rotate,
                updated_at: $('#crop-modal').data('updated-at')
            })
        }).then(r => {
            if (r.status === 409) { alert('Image was modified by another user. Please refresh.'); return null; }
            return r.json();
        }).then(data => {
            if (data && data.success) {
                cropper.destroy(); cropper = null;
                $('#crop-modal').modal('hide');
                resetGrid(); loadGridPage();
            }
        });
    });
    $('#crop-modal').on('hidden.bs.modal', function() {
        if (cropper) { cropper.destroy(); cropper = null; }
    });

    // --- Cache ---
    $('#detail-regen-cache').on('click', function() {
        fetch(baseUrl + '/' + currentMediaId + '/cache', { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF } })
            .then(r => r.json()).then(() => alert('{{ trans('vela::global.cache_regenerated') }}'));
    });
    $('#detail-clear-cache').on('click', function() {
        fetch(baseUrl + '/' + currentMediaId + '/cache', { method: 'DELETE', headers: { 'X-CSRF-TOKEN': CSRF } })
            .then(r => r.json()).then(() => alert('{{ trans('vela::global.cache_cleared_msg') }}'));
    });

    // --- Delete ---
    $('#detail-delete-btn').on('click', function() {
        fetch(baseUrl + '/' + currentMediaId, { headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF } })
            .then(r => r.json()).then(data => {
                const count = (data.used_in || []).length;
                $('#delete-usage-count').text(count);
                $('#delete-usage-list').empty();
                (data.used_in || []).forEach(ref => {
                    const li = $('<li>').text((ref.label || 'Unknown') + ' (' + (ref.type || '') + ')');
                    $('#delete-usage-list').append(li);
                });
                $('#delete-confirm-modal').data('media-id', currentMediaId);
                $('#media-detail-modal').modal('hide');
                $('#delete-confirm-modal').modal('show');
            });
    });
    $('#confirm-delete-btn').on('click', function() {
        fetch(baseUrl + '/' + $('#delete-confirm-modal').data('media-id'), {
            method: 'DELETE', headers: { 'X-CSRF-TOKEN': CSRF }
        }).then(r => r.json()).then(() => {
            $('#delete-confirm-modal').modal('hide');
            resetGrid(); loadGridPage();
        });
    });
    // Bulk delete
    $(document).on('change', '.media-checkbox', function() {
        const checked = $('.media-checkbox:checked').length;
        $('#bulk-delete-btn').toggle(checked > 0);
    });
    $('#select-all').on('change', function() {
        $('.media-checkbox').prop('checked', $(this).is(':checked'));
        const checked = $('.media-checkbox:checked').length;
        $('#bulk-delete-btn').toggle(checked > 0);
    });
    $('#bulk-delete-btn').on('click', function() {
        const ids = $('.media-checkbox:checked').map(function() { return parseInt($(this).val()); }).get();
        if (!ids.length || !confirm(translations.deleteSelectedConfirm.replace(':count', ids.length))) return;
        fetch(baseUrl + '/destroy', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ ids: ids })
        }).then(r => r.json()).then(() => { resetGrid(); loadGridPage(); if (dtInitialized) $('#media-datatable').DataTable().ajax.reload(); });
    });

    // --- AI Generate ---
    $('#ai-generate-btn').on('click', function() { $('#ai-generate-modal').modal('show'); });
    $('#ai-generate-submit').on('click', function() {
        const prompt = $('#ai-prompt').val();
        if (!prompt) return;
        $('#ai-loading').show();
        $('#ai-generate-submit').prop('disabled', true);
        fetch(baseUrl + '/generate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ prompt: prompt })
        }).then(r => r.json()).then(data => {
            $('#ai-loading').hide();
            $('#ai-generate-submit').prop('disabled', false);
            if (data.success) {
                $('#ai-generate-modal').modal('hide');
                $('#ai-prompt').val('');
                resetGrid(); loadGridPage();
            } else { alert(data.message || 'Generation failed'); }
        }).catch(() => {
            $('#ai-loading').hide();
            $('#ai-generate-submit').prop('disabled', false);
            alert(translations.errorGenerating);
        });
    });
})(jQuery);
</script>
@endsection
