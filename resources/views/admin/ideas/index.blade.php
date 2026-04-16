@extends('vela::layouts.admin')
@section('content')
@can('idea_create')
    <div style="margin-bottom: 10px;" class="row">
        <div class="col-lg-12">
            <a class="btn btn-success" href="{{ route('vela.admin.ideas.create') }}">
                <span class="fa fa-plus"></span>
                {{ trans('vela::global.add') }} {{ trans('vela::cruds.idea.title_singular') }}
            </a>
            <button class="btn btn-info" data-toggle="modal" data-target="#aiGenerateModal">
                🪄  {{ trans('vela::global.ai_generate_ideas') }}
            </button>
            <button class="btn btn-primary" id="bulkGenerateBtn" disabled>
                📝 {{ trans('vela::global.bulk_generate_content') }}
            </button>
            <button class="btn btn-warning" data-toggle="modal" data-target="#csvImportModal">
                {{ trans('vela::global.app_csvImport') }}
            </button>
            @include('vela::csvImport.modal', ['model' => 'Idea', 'route' => 'vela.admin.ideas.parseCsvImport'])
        </div>
    </div>
@endcan

<!-- Filters -->
<div class="card mb-3">
    <div class="card-header">
        <h6 class="mb-0">{{ trans('vela::global.filters') }}</h6>
    </div>
    <div class="card-body">
        <form id="filterForm" class="row">
            <div class="col-md-4">
                <label for="statusFilter">{{ trans('vela::global.status') }}</label>
                <select class="form-control" id="statusFilter" name="status">
                    <option value="">{{ trans('vela::global.all_statuses') }}</option>
                    @foreach(\VelaBuild\Core\Models\Idea::STATUS_FILTERS as $key => $label)
                        <option value="{{ $key }}" {{ (request('status', 'open') == $key) ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="categoryFilter">{{ trans('vela::global.category') }}</label>
                <select class="form-control" id="categoryFilter" name="category">
                    <option value="">{{ trans('vela::global.all_categories') }}</option>
                    @foreach(\VelaBuild\Core\Models\Category::all() as $category)
                        <option value="{{ $category->id }}" {{ request('category') == $category->id ? 'selected' : '' }}>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label>&nbsp;</label>
                <div>
                    <button type="submit" class="btn btn-primary">{{ trans('vela::global.filter') }}</button>
                    <button type="button" class="btn btn-secondary" id="clearFiltersBtn">{{ trans('vela::global.clear') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        {{ trans('vela::cruds.idea.title_singular') }} {{ trans('vela::global.list') }}
    </div>

    <div class="card-body">
        <table class=" table table-bordered table-striped table-hover ajaxTable datatable datatable-Idea">
            <thead>
                <tr>
                    <th width="10">

                    </th>
                    <th>
                        {{ trans('vela::cruds.idea.fields.id') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.idea.fields.name') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.idea.fields.details') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.idea.fields.status') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.idea.fields.category') }}
                    </th>
                    <th>
                        &nbsp;
                    </th>
                </tr>
            </thead>
        </table>
    </div>
</div>

<!-- AI Generate Ideas Modal -->
<div class="modal fade" id="aiGenerateModal" tabindex="-1" role="dialog" aria-labelledby="aiGenerateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="aiGenerateModalLabel">🪄 {{ trans('vela::global.ai_generate_ideas') }}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="aiGenerateForm">
                    <div class="form-group">
                        <label for="topic">{{ trans('vela::global.topic_optional') }}</label>
                        <input type="text" class="form-control" id="topic" name="topic" placeholder="e.g., Getting started guides, Product reviews, Industry trends">
                        <small class="form-text text-muted">{{ trans('vela::global.topic_help') }}</small>
                    </div>
                    <div class="form-group">
                        <label for="keyword">{{ trans('vela::global.keywords_optional') }}</label>
                        <input type="text" class="form-control" id="keyword" name="keyword" placeholder="e.g., tutorials, best practices, tips and tricks, how-to guides">
                        <small class="form-text text-muted">{{ trans('vela::global.keywords_help') }}</small>
                    </div>
                    <div class="form-group">
                        <label for="count">{{ trans('vela::global.number_of_ideas') }}</label>
                        <select class="form-control" id="count" name="count">
                            <option value="1">{{ trans_choice('vela::global.idea_count', 1) }}</option>
                            <option value="2">{{ trans_choice('vela::global.idea_count', 2) }}</option>
                            <option value="5" selected>{{ trans_choice('vela::global.idea_count', 5) }}</option>
                            <option value="10">{{ trans_choice('vela::global.idea_count', 10) }}</option>
                            <option value="15">{{ trans_choice('vela::global.idea_count', 15) }}</option>
                            <option value="20">{{ trans_choice('vela::global.idea_count', 20) }}</option>
                            <option value="25">{{ trans_choice('vela::global.idea_count', 25) }}</option>
                            <option value="30">{{ trans_choice('vela::global.idea_count', 30) }}</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="categories">{{ trans('vela::global.categories_optional') }}</label>
                        <select class="form-control" id="categories" name="categories[]" multiple>
                            @foreach(\VelaBuild\Core\Models\Category::all() as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                        <small class="form-text text-muted">{{ trans('vela::global.categories_help') }}</small>
                    </div>
                </form>

                <!-- Loading State -->
                <div id="aiLoading" class="text-center" style="display: none;">
                    <div class="spinner-border text-primary" role="status">
                        <span class="sr-only">{{ trans('vela::global.generating_ideas') }}</span>
                    </div>
                    <p class="mt-2">{{ trans('vela::global.generating_ai_ideas') }}</p>
                </div>

                <!-- Generated Ideas -->
                <div id="generatedIdeas" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="mb-0">{{ trans('vela::global.generated_ideas_select') }}</h6>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="selectAllBtn">{{ trans('vela::global.select_all') }}</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="selectNoneBtn">{{ trans('vela::global.select_none') }}</button>
                        </div>
                    </div>
                    <div id="ideasList" class="list-group">
                        <!-- Ideas will be populated here -->
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ trans('vela::global.cancel') }}</button>
                <button type="button" class="btn btn-primary" id="generateBtn">{{ trans('vela::global.generate_ideas') }}</button>
                <button type="button" class="btn btn-success" id="saveSelectedBtn" style="display: none;">{{ trans('vela::global.save_selected_ideas') }}</button>
            </div>
        </div>
    </div>
</div>

@endsection
@section('scripts')
@parent
<script>

// AI Generate Ideas functionality
$(document).ready(function() {
    const translations = {
        selectOneIdea: '{{ trans('vela::global.select_one_idea') }}',
        errorGeneratingIdeas: '{{ trans('vela::global.error_generating_ideas') }}',
        errorSavingIdeas: '{{ trans('vela::global.error_saving_ideas') }}',
        contentGeneratedSuccess: '{{ trans('vela::global.content_generated_success') }}',
        errorGeneratingContent: '{{ trans('vela::global.error_generating_content') }}',
        generateContentConfirm: '{{ trans('vela::global.generate_content_confirm') }}',
        selectOneContent: '{{ trans('vela::global.select_one_content') }}',
        bulkGenerateConfirm: '{{ trans('vela::global.bulk_generate_confirm') }}',
        queuedSuccess: '{{ trans('vela::global.queued_success') }}',
        errorBulkGeneration: '{{ trans('vela::global.error_bulk_generation') }}',
        generating: '{{ trans('vela::global.generating') }}',
        processing: '{{ trans('vela::global.processing') }}',
        bulkGenerateContent: '{{ trans('vela::global.bulk_generate_content') }}',
        generate: '{{ trans('vela::global.generate') }}',
    };

    // Set initial filter values
    $('#statusFilter').val('open');

    // Wait a bit to ensure DOM is fully ready
    setTimeout(function() {
        // Define dtButtons here
        let dtButtons = $.extend(true, [], $.fn.dataTable.defaults.buttons);
        @can('idea_delete')
        let deleteButtonTrans = '{{ trans('vela::global.datatables.delete') }}';
        let deleteButton = {
            text: deleteButtonTrans,
            url: "{{ route('vela.admin.ideas.massDestroy') }}",
            className: 'btn-danger',
            action: function (e, dt, node, config) {
                var ids = $.map(dt.rows({ selected: true }).data(), function (entry) {
                    return entry.id
                });

                if (ids.length === 0) {
                    alert('{{ trans('vela::global.datatables.zero_selected') }}')
                    return
                }

                if (confirm('{{ trans('vela::global.areYouSure') }}')) {
                    $.ajax({
                        headers: {'x-csrf-token': _token},
                        method: 'POST',
                        url: config.url,
                        data: { ids: ids, _method: 'DELETE' }})
                        .done(function () { location.reload() })
                }
            }
        }
        dtButtons.push(deleteButton)
        @endcan

        // DataTable configuration
        let dtOverrideGlobals = {
            buttons: dtButtons,
            processing: true,
            serverSide: true,
            retrieve: true,
            aaSorting: [],
            ajax: {
                url: "{{ route('vela.admin.ideas.index') }}",
                data: function(d) {
                    d.status = $('#statusFilter').val() || 'open';
                    d.category = $('#categoryFilter').val() || '';
                    console.log('DataTable AJAX data:', d);
                }
            },
            columns: [
                { data: 'placeholder', name: 'placeholder' },
                { data: 'id', name: 'id' },
                { data: 'name', name: 'name' },
                { data: 'details', name: 'details' },
                { data: 'status', name: 'status' },
                { data: 'category', name: 'category.name' },
                { data: 'actions', name: '{{ trans('vela::global.actions') }}' }
            ],
            orderCellsTop: true,
            order: [[ 1, 'desc' ]],
            pageLength: 100,
        };

        // Initialize DataTable with proper filter values
        let table = $('.datatable-Idea').DataTable(dtOverrideGlobals);
    }, 100);

    $('a[data-toggle="tab"]').on('shown.bs.tab click', function(e){
        $($.fn.dataTable.tables(true)).DataTable()
            .columns.adjust();
    });

    let generatedIdeas = [];

    // Generate Ideas Button
    $('#generateBtn').click(function() {
        const topic = $('#topic').val();
        const keyword = $('#keyword').val();
        const count = $('#count').val();
        const categories = $('#categories').val() || [];

        // Show loading state
        $('#aiLoading').show();
        $('#generatedIdeas').hide();
        $('#saveSelectedBtn').hide();
        $(this).prop('disabled', true);

        // Make AJAX request
        $.ajax({
            url: '{{ route("vela.admin.ideas.generateAi") }}',
            method: 'POST',
            data: {
                topic: topic,
                keyword: keyword,
                count: count,
                categories: categories,
                _token: '{{ csrf_token() }}'
            },
            success: function(response) {
                if (response.success) {
                    generatedIdeas = response.ideas;
                    displayIdeas(response.ideas);
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function(xhr) {
                alert(translations.errorGeneratingIdeas);
                console.error(xhr);
            },
            complete: function() {
                $('#aiLoading').hide();
                $('#generateBtn').prop('disabled', false);
            }
        });
    });

    // Display generated ideas
    function displayIdeas(ideas) {
        const ideasList = $('#ideasList');
        ideasList.empty();

        ideas.forEach(function(idea, index) {
            const categoryBadge = idea.category ? `<span class="badge badge-info ml-2">${idea.category}</span>` : '';
            const keywordBadge = idea.keyword ? `<span class="badge badge-secondary ml-1">Keyword: ${idea.keyword}</span>` : '';
            const ideaHtml = `
                <div class="list-group-item">
                    <div class="form-check">
                        <input class="form-check-input idea-checkbox" type="checkbox" value="${index}" id="idea-${index}">
                        <label class="form-check-label" for="idea-${index}">
                            <strong>${idea.title}</strong>
                            ${categoryBadge}
                            ${keywordBadge}
                            <br>
                            <small class="text-muted">${idea.description}</small>
                        </label>
                    </div>
                </div>
            `;
            ideasList.append(ideaHtml);
        });

        $('#generatedIdeas').show();
        $('#saveSelectedBtn').show();
    }

    // Select All Button
    $('#selectAllBtn').click(function() {
        $('.idea-checkbox').prop('checked', true);
    });

    // Select None Button
    $('#selectNoneBtn').click(function() {
        $('.idea-checkbox').prop('checked', false);
    });

    // Save Selected Ideas Button
    $('#saveSelectedBtn').click(function() {
        const selectedIdeas = [];

        $('.idea-checkbox:checked').each(function() {
            const index = $(this).val();
            selectedIdeas.push(generatedIdeas[index]);
        });

        if (selectedIdeas.length === 0) {
            alert(translations.selectOneIdea);
            return;
        }

        $(this).prop('disabled', true);

        $.ajax({
            url: '{{ route("vela.admin.ideas.saveAi") }}',
            method: 'POST',
            data: {
                ideas: selectedIdeas,
                _token: '{{ csrf_token() }}'
            },
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    $('#aiGenerateModal').modal('hide');
                    location.reload(); // Refresh the page to show new ideas
                } else {
                    alert('Error: ' + response.message);
                }
            },
            error: function(xhr) {
                alert(translations.errorSavingIdeas);
                console.error(xhr);
            },
            complete: function() {
                $('#saveSelectedBtn').prop('disabled', false);
            }
        });
    });

    // Reset modal when closed
    $('#aiGenerateModal').on('hidden.bs.modal', function() {
        $('#aiGenerateForm')[0].reset();
        $('#generatedIdeas').hide();
        $('#saveSelectedBtn').hide();
        generatedIdeas = [];
    });

    // Handle filter form submission
    $('#filterForm').on('submit', function(e) {
        e.preventDefault();
        $('.datatable-Idea').DataTable().ajax.reload();
    });

    // Handle clear filters button
    $('#clearFiltersBtn').on('click', function() {
        $('#statusFilter').val('');
        $('#categoryFilter').val('');
        // Send a special parameter to indicate filters were cleared
        $('.datatable-Idea').DataTable().ajax.url("{{ route('vela.admin.ideas.index') }}?cleared=1").load();
    });

    // Handle generate content buttons in DataTable
    $(document).on('click', '.generate-content-btn', function() {
        const ideaId = $(this).data('idea-id');
        const button = $(this);

        if (confirm(translations.generateContentConfirm)) {
            button.prop('disabled', true).text(translations.generating);

            $.ajax({
                url: '{{ route("vela.admin.ideas.generateContent") }}',
                method: 'POST',
                data: {
                    idea_id: ideaId,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    if (response.success) {
                        alert(translations.contentGeneratedSuccess);
                        $('.datatable-Idea').DataTable().ajax.reload();
                    } else {
                        alert('Error: ' + response.message);
                        button.prop('disabled', false).text('🤖 ' + translations.generate);
                    }
                },
                error: function(xhr) {
                    alert(translations.errorGeneratingContent);
                    button.prop('disabled', false).text('🤖 ' + translations.generate);
                }
            });
        }
    });

    // Bulk generate functionality
    let selectedIdeas = [];

    // Handle DataTable select events
    $('.datatable-Idea').on('select.dt deselect.dt', function() {
        updateBulkGenerateButton();
    });

    // Update bulk generate button state
    function updateBulkGenerateButton() {
        const selectedRows = $('.datatable-Idea tbody tr.selected');
        const bulkBtn = $('#bulkGenerateBtn');

        if (selectedRows.length > 0) {
            bulkBtn.prop('disabled', false);
            bulkBtn.text('📝 ' + translations.bulkGenerateContent + ' (' + selectedRows.length + ')');
        } else {
            bulkBtn.prop('disabled', true);
            bulkBtn.text('📝 ' + translations.bulkGenerateContent);
        }
    }

    // Handle bulk generate button click
    $('#bulkGenerateBtn').on('click', function() {
        const table = $('.datatable-Idea').DataTable();
        const selectedRows = table.rows('.selected').data();
        const ideaIds = [];

        console.log('Selected rows:', selectedRows);

        selectedRows.each(function(row) {
            console.log('Row data:', row);
            if (row && row.id) {
                ideaIds.push(row.id);
            }
        });

        console.log('Idea IDs:', ideaIds);

        if (ideaIds.length === 0) {
            alert(translations.selectOneContent);
            return;
        }

        if (confirm(translations.bulkGenerateConfirm.replace(':count', ideaIds.length))) {
            const button = $(this);
            button.prop('disabled', true).text(translations.processing);

            $.ajax({
                url: '{{ route("vela.admin.ideas.bulkGenerateContent") }}',
                method: 'POST',
                data: {
                    idea_ids: ideaIds,
                    _token: '{{ csrf_token() }}'
                },
                success: function(response) {
                    if (response.success) {
                        alert(translations.queuedSuccess.replace(':count', response.count));
                        $('.datatable-Idea').DataTable().ajax.reload(null, false);
                        updateBulkGenerateButton();
                    } else {
                        alert('Error: ' + response.message);
                        button.prop('disabled', false).text('📝 ' + translations.bulkGenerateContent);
                    }
                },
                error: function(xhr) {
                    alert(translations.errorBulkGeneration);
                    button.prop('disabled', false).text('📝 ' + translations.bulkGenerateContent);
                }
            });
        }
    });
});

</script>
@endsection
