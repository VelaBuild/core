@extends('vela::layouts.admin')
@section('content')

<div class="card">
    <div class="card-header">
        {{ trans('vela::cruds.formSubmission.title') }} {{ trans('vela::global.list') }}
    </div>

    <div class="card-body">
        <table class=" table table-bordered table-striped table-hover ajaxTable datatable datatable-FormSubmission">
            <thead>
                <tr>
                    <th width="10">

                    </th>
                    <th>
                        {{ trans('vela::cruds.formSubmission.fields.id') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.formSubmission.fields.page') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.formSubmission.fields.data') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.formSubmission.fields.ip_address') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.formSubmission.fields.is_read') }}
                    </th>
                    <th>
                        {{ trans('vela::cruds.formSubmission.fields.created_at') }}
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
@can('form_submission_delete')
  let deleteButtonTrans = '{{ trans('vela::global.datatables.delete') }}';
  let deleteButton = {
    text: deleteButtonTrans,
    url: "{{ route('vela.admin.form-submissions.massDestroy') }}",
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
      url: "{{ route('vela.admin.form-submissions.index') }}",
    },
    columns: [
      { data: 'placeholder', name: 'placeholder' },
{ data: 'id', name: 'id' },
{ data: 'page_title', name: 'page.title' },
{ data: 'preview', name: 'preview', sortable: false, searchable: false },
{ data: 'ip_address', name: 'ip_address' },
{ data: 'is_read', name: 'is_read' },
{ data: 'created_at', name: 'created_at' },
{ data: 'actions', name: '{{ trans('vela::global.actions') }}' }
    ],
    orderCellsTop: true,
    order: [[ 1, 'desc' ]],
    pageLength: 100,
  };
  let table = $('.datatable-FormSubmission').DataTable(dtOverrideGlobals);
  $('a[data-toggle="tab"]').on('shown.bs.tab click', function(e){
      $($.fn.dataTable.tables(true)).DataTable()
          .columns.adjust();
  });

});

</script>
@endsection
