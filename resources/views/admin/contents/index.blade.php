@extends('vela::layouts.admin')
@section('content')
    <div style="margin-bottom: 10px;" class="row">
        <div class="col-lg-12">
            @can('article_create')
            <a class="btn btn-success" href="{{ route('vela.admin.contents.create') }}">
                {{ trans('vela::global.add') }} {{ trans('vela::cruds.content.title_singular') }}
            </a>
            <button class="btn btn-warning" data-toggle="modal" data-target="#csvImportModal">
                {{ trans('vela::global.app_csvImport') }}
            </button>
            <button class="btn btn-primary" id="bulkPublishBtn" disabled>
                📢 {{ trans('vela::global.bulk_publish') }}
            </button>
            @include('vela::csvImport.modal', ['model' => 'Content', 'route' => 'vela.admin.contents.parseCsvImport'])
            @endcan
            @can('idea_access')
            <a class="btn btn-info" href="{{ route('vela.admin.ideas.index') }}">
                <i class="fas fa-lightbulb"></i> {{ trans('vela::global.ideas') }}
            </a>
            @endcan
        </div>
    </div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <label for="category_filter">{{ trans('vela::global.category_filter') }}</label>
                <select id="category_filter" class="form-control">
                    <option value="">{{ trans('vela::global.all_categories') }}</option>
                    @foreach(\VelaBuild\Core\Models\Category::all() as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="status_filter">{{ trans('vela::global.status_filter') }}</label>
                <select id="status_filter" class="form-control">
                    <option value="">{{ trans('vela::global.all_statuses') }}</option>
                    @foreach(\VelaBuild\Core\Models\Content::STATUS_SELECT as $key => $value)
                        <option value="{{ $key }}">{{ trans('vela::global.status_' . $key) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label>&nbsp;</label>
                <div>
                    <button id="clear_filters" class="btn btn-secondary">{{ trans('vela::global.clear_filters') }}</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        {{ trans('vela::cruds.content.title_singular') }} {{ trans('vela::global.list') }}
    </div>

    <div class="card-body">
        <table class=" table table-bordered table-striped table-hover ajaxTable datatable datatable-Content">
            <thead>
                <tr>
                    <th width="10">

                    </th>
                    <th>
                        {{ trans('vela::cruds.content.fields.id') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.content.fields.title') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.content.fields.slug') }}
                    </th>

                    <th>
                        {{ trans('vela::cruds.content.fields.description') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.content.fields.main_image') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.content.fields.author') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.content.fields.categories') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.content.fields.status') }}
                    </th>
                    <th>
                        &nbsp;
                    </th>
                </tr>
            </thead>
        </table>
    </div>
</div>



@endsection
@section('scripts')
@parent
<script>
    $(function () {
  let dtButtons = $.extend(true, [], $.fn.dataTable.defaults.buttons)
@can('article_delete')
  let deleteButtonTrans = '{{ trans('vela::global.datatables.delete') }}';
  let deleteButton = {
    text: deleteButtonTrans,
    url: "{{ route('vela.admin.contents.massDestroy') }}",
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

  let dtOverrideGlobals = {
    buttons: dtButtons,
    processing: true,
    serverSide: true,
    retrieve: true,
    aaSorting: [],
    ajax: {
      url: "{{ route('vela.admin.contents.index') }}",
      data: function(d) {
        d.category_filter = $('#category_filter').val();
        d.status_filter = $('#status_filter').val();
      }
    },
    columns: [
      { data: 'placeholder', name: 'placeholder' },
{ data: 'id', name: 'id' },
{ data: 'title', name: 'title' },
{ data: 'slug', name: 'slug' },

{ data: 'description', name: 'description' },
{ data: 'main_image', name: 'main_image', sortable: false, searchable: false },
{ data: 'author_name', name: 'author.name' },
{ data: 'categories', name: 'categories', sortable: false, searchable: false },
{ data: 'status', name: 'status' },
{ data: 'actions', name: '{{ trans('vela::global.actions') }}' }
    ],
    orderCellsTop: true,
    order: [[ 1, 'desc' ]],
    pageLength: 100,
  };
  let table = $('.datatable-Content').DataTable(dtOverrideGlobals);
  $('a[data-toggle="tab"]').on('shown.bs.tab click', function(e){
      $($.fn.dataTable.tables(true)).DataTable()
          .columns.adjust();
  });

  const translations = {
      selectContentToPublish: '{{ trans('vela::global.select_content_to_publish') }}',
      bulkPublishConfirm: '{{ trans('vela::global.bulk_publish_confirm') }}',
      publishing: '{{ trans('vela::global.publishing') }}',
      publishSuccess: '{{ trans('vela::global.publish_success') }}',
      errorPublishing: '{{ trans('vela::global.error_publishing') }}',
      bulkPublish: '{{ trans('vela::global.bulk_publish') }}',
  };

  // Handle DataTable select events for bulk publish
  $('.datatable-Content').on('select.dt deselect.dt', function() {
      updateBulkPublishButton();
  });

  // Update bulk publish button state
  function updateBulkPublishButton() {
      const selectedRows = $('.datatable-Content tbody tr.selected');
      const bulkBtn = $('#bulkPublishBtn');

      if (selectedRows.length > 0) {
          bulkBtn.prop('disabled', false);
          bulkBtn.text('📢 ' + translations.bulkPublish + ' (' + selectedRows.length + ')');
      } else {
          bulkBtn.prop('disabled', true);
          bulkBtn.text('📢 ' + translations.bulkPublish);
      }
  }

  // Handle bulk publish button click
  $('#bulkPublishBtn').on('click', function() {
      const table = $('.datatable-Content').DataTable();
      const selectedRows = table.rows('.selected').data();
      const contentIds = [];

      selectedRows.each(function(row) {
          if (row && row.id) {
              contentIds.push(row.id);
          }
      });

      if (contentIds.length === 0) {
          alert(translations.selectContentToPublish);
          return;
      }

      if (confirm(translations.bulkPublishConfirm.replace(':count', contentIds.length))) {
          const button = $(this);
          button.prop('disabled', true).text(translations.publishing);

          $.ajax({
              url: '{{ route("vela.admin.contents.massPublish") }}',
              method: 'POST',
              data: {
                  ids: contentIds,
                  _token: '{{ csrf_token() }}'
              },
              success: function(response) {
                  if (response.success) {
                      alert(translations.publishSuccess.replace(':count', response.count));
                      $('.datatable-Content').DataTable().ajax.reload(null, false);
                      updateBulkPublishButton();
                  } else {
                      alert('Error: ' + response.message);
                      button.prop('disabled', false).text('📢 ' + translations.bulkPublish);
                  }
              },
              error: function(xhr) {
                  alert(translations.errorPublishing);
                  button.prop('disabled', false).text('📢 ' + translations.bulkPublish);
              }
          });
      }
  });

  // Filter functionality
  $('#category_filter, #status_filter').on('change', function() {
      table.draw();
  });

  $('#clear_filters').on('click', function() {
      $('#category_filter, #status_filter').val('').trigger('change');
  });

});

</script>
@endsection
