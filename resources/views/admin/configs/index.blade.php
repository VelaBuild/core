@extends('vela::layouts.admin')
@section('content')
    <div style="margin-bottom: 10px;" class="row">
        <div class="col-lg-12">
            @can('config_create')
            <a class="btn btn-success" href="{{ route('vela.admin.configs.create') }}">
                {{ trans('vela::global.add') }} {{ trans('vela::cruds.config.title_singular') }}
            </a>
            @endcan
            @can('config_edit')
            <a class="btn btn-info" href="{{ route('vela.admin.ai-settings.index') }}">
                <i class="fas fa-robot"></i> AI Settings
            </a>
            @endcan
        </div>
    </div>
<div class="card">
    <div class="card-header">
        {{ trans('vela::cruds.config.title_singular') }} {{ trans('vela::global.list') }}
    </div>

    <div class="card-body">
        <table class=" table table-bordered table-striped table-hover ajaxTable datatable datatable-Config">
            <thead>
                <tr>
                    <th width="10">

                    </th>
                    <th>
                        {{ trans('vela::cruds.config.fields.id') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.config.fields.key') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.config.fields.value') }}
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
@can('config_delete')
  let deleteButtonTrans = '{{ trans('vela::global.datatables.delete') }}';
  let deleteButton = {
    text: deleteButtonTrans,
    url: "{{ route('vela.admin.configs.massDestroy') }}",
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
    ajax: "{{ route('vela.admin.configs.index') }}",
    columns: [
      { data: 'placeholder', name: 'placeholder' },
{ data: 'id', name: 'id' },
{ data: 'key', name: 'key' },
{ data: 'value', name: 'value' },
{ data: 'actions', name: '{{ trans('vela::global.actions') }}' }
    ],
    orderCellsTop: true,
    order: [[ 1, 'desc' ]],
    pageLength: 100,
  };
  let table = $('.datatable-Config').DataTable(dtOverrideGlobals);
  $('a[data-toggle="tab"]').on('shown.bs.tab click', function(e){
      $($.fn.dataTable.tables(true)).DataTable()
          .columns.adjust();
  });

});

</script>
@endsection
