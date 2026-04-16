@extends('vela::layouts.admin')
@section('content')
@can('translation_create')
    <div style="margin-bottom: 10px;" class="row">
        <div class="col-lg-12">
            <a class="btn btn-success" href="{{ route('vela.admin.translations.create') }}">
                {{ trans('vela::global.add') }} {{ trans('vela::cruds.translation.title_singular') }}
            </a>
            <button class="btn btn-warning" data-toggle="modal" data-target="#csvImportModal">
                {{ trans('vela::global.app_csvImport') }}
            </button>
            @include('vela::csvImport.modal', ['model' => 'Translation', 'route' => 'vela.admin.translations.parseCsvImport'])
        </div>
    </div>
@endcan
<div class="card">
    <div class="card-header">
        {{ trans('vela::cruds.translation.title_singular') }} {{ trans('vela::global.list') }}
    </div>

    <div class="card-body">
        <table class=" table table-bordered table-striped table-hover ajaxTable datatable datatable-Translation">
            <thead>
                <tr>
                    <th width="10">

                    </th>
                    <th>
                        {{ trans('vela::cruds.translation.fields.id') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.translation.fields.lang_code') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.translation.fields.model_type') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.translation.fields.model_key') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.translation.fields.translation') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.translation.fields.notes') }}
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
@can('translation_delete')
  let deleteButtonTrans = '{{ trans('vela::global.datatables.delete') }}';
  let deleteButton = {
    text: deleteButtonTrans,
    url: "{{ route('vela.admin.translations.massDestroy') }}",
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
    ajax: "{{ route('vela.admin.translations.index') }}",
    columns: [
      { data: 'placeholder', name: 'placeholder' },
{ data: 'id', name: 'id' },
{ data: 'lang_code', name: 'lang_code' },
{ data: 'model_type', name: 'model_type' },
{ data: 'model_key', name: 'model_key' },
{ data: 'translation', name: 'translation' },
{ data: 'notes', name: 'notes' },
{ data: 'actions', name: '{{ trans('vela::global.actions') }}' }
    ],
    orderCellsTop: true,
    order: [[ 1, 'desc' ]],
    pageLength: 100,
  };
  let table = $('.datatable-Translation').DataTable(dtOverrideGlobals);
  $('a[data-toggle="tab"]').on('shown.bs.tab click', function(e){
      $($.fn.dataTable.tables(true)).DataTable()
          .columns.adjust();
  });

});

</script>
@endsection
