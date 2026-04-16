@can('comment_create')
    <div style="margin-bottom: 10px;" class="row">
        <div class="col-lg-12">
            <a class="btn btn-success" href="{{ route('vela.admin.comments.create') }}">
                {{ trans('vela::global.add') }} {{ trans('vela::cruds.comment.title_singular') }}
            </a>
        </div>
    </div>
@endcan

<div class="card">
    <div class="card-header">
        {{ trans('vela::cruds.comment.title_singular') }} {{ trans('vela::global.list') }}
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class=" table table-bordered table-striped table-hover datatable datatable-userComments">
                <thead>
                    <tr>
                        <th width="10">

                        </th>
                        <th>
                            {{ trans('vela::cruds.comment.fields.id') }}
                        </th>
                        <th>
                            {{ trans('vela::cruds.comment.fields.user') }}
                        </th>
                        <th>
                            {{ trans('vela::cruds.comment.fields.comment') }}
                        </th>
                        <th>
                            {{ trans('vela::cruds.comment.fields.status') }}
                        </th>
                        <th>
                            {{ trans('vela::cruds.comment.fields.parent') }}
                        </th>
                        <th>
                            &nbsp;
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($comments as $key => $comment)
                        <tr data-entry-id="{{ $comment->id }}">
                            <td>

                            </td>
                            <td>
                                {{ $comment->id ?? '' }}
                            </td>
                            <td>
                                {{ $comment->user->name ?? '' }}
                            </td>
                            <td>
                                {{ $comment->comment ?? '' }}
                            </td>
                            <td>
                                {{ $comment->status ? trans('vela::global.status_' . $comment->status) : '' }}
                            </td>
                            <td>
                                {{ $comment->parent ?? '' }}
                            </td>
                            <td>
                                @can('comment_show')
                                    <a class="btn btn-xs btn-primary" href="{{ route('vela.admin.comments.show', $comment->id) }}">
                                        {{ trans('vela::global.view') }}
                                    </a>
                                @endcan

                                @can('comment_edit')
                                    <a class="btn btn-xs btn-info" href="{{ route('vela.admin.comments.edit', $comment->id) }}">
                                        {{ trans('vela::global.edit') }}
                                    </a>
                                @endcan

                                @can('comment_delete')
                                    <form action="{{ route('vela.admin.comments.destroy', $comment->id) }}" method="POST" onsubmit="return confirm('{{ trans('vela::global.areYouSure') }}');" style="display: inline-block;">
                                        <input type="hidden" name="_method" value="DELETE">
                                        <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                        <input type="submit" class="btn btn-xs btn-danger" value="{{ trans('vela::global.delete') }}">
                                    </form>
                                @endcan

                            </td>

                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@section('scripts')
@parent
<script>
    $(function () {
  let dtButtons = $.extend(true, [], $.fn.dataTable.defaults.buttons)
@can('comment_delete')
  let deleteButtonTrans = '{{ trans('vela::global.datatables.delete') }}'
  let deleteButton = {
    text: deleteButtonTrans,
    url: "{{ route('vela.admin.comments.massDestroy') }}",
    className: 'btn-danger',
    action: function (e, dt, node, config) {
      var ids = $.map(dt.rows({ selected: true }).nodes(), function (entry) {
          return $(entry).data('entry-id')
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

  $.extend(true, $.fn.dataTable.defaults, {
    orderCellsTop: true,
    order: [[ 1, 'desc' ]],
    pageLength: 100,
  });
  let table = $('.datatable-userComments:not(.ajaxTable)').DataTable({ buttons: dtButtons })
  $('a[data-toggle="tab"]').on('shown.bs.tab click', function(e){
      $($.fn.dataTable.tables(true)).DataTable()
          .columns.adjust();
  });

})

</script>
@endsection
