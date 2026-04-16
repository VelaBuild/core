@can('article_create')
    <div style="margin-bottom: 10px;" class="row">
        <div class="col-lg-12">
            <a class="btn btn-success" href="{{ route('vela.admin.contents.create') }}">
                {{ trans('vela::global.add') }} {{ trans('vela::cruds.content.title_singular') }}
            </a>
        </div>
    </div>
@endcan

<div class="card">
    <div class="card-header">
        {{ trans('vela::cruds.content.title_singular') }} {{ trans('vela::global.list') }}
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class=" table table-bordered table-striped table-hover datatable datatable-authorContents">
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
                            {{ trans('vela::cruds.content.fields.type') }}
                        </th>
                        <th>
                            {{ trans('vela::cruds.content.fields.description') }}
                        </th>
                        <th>
                            {{ trans('vela::cruds.content.fields.main_image') }}
                        </th>
                        <th>
                            {{ trans('vela::cruds.content.fields.gallery') }}
                        </th>
                        <th>
                            {{ trans('vela::cruds.content.fields.author') }}
                        </th>
                        <th>
                            {{ trans('vela::cruds.content.fields.status') }}
                        </th>
                        <th>
                            {{ trans('vela::cruds.content.fields.published_at') }}
                        </th>
                        <th>
                            &nbsp;
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($contents as $key => $content)
                        <tr data-entry-id="{{ $content->id }}">
                            <td>

                            </td>
                            <td>
                                {{ $content->id ?? '' }}
                            </td>
                            <td>
                                {{ $content->title ?? '' }}
                            </td>
                            <td>
                                {{ $content->slug ?? '' }}
                            </td>
                            <td>
                                {{ ucfirst($content->type ?? 'post') }}
                            </td>
                            <td>
                                {{ $content->description ?? '' }}
                            </td>
                            <td>
                                @if($content->main_image)
                                    <a href="{{ $content->main_image->getUrl() }}" target="_blank" style="display: inline-block">
                                        <img src="{{ $content->main_image->getUrl('thumb') }}">
                                    </a>
                                @endif
                            </td>
                            <td>
                                @foreach($content->gallery as $key => $media)
                                    <a href="{{ $media->getUrl() }}" target="_blank" style="display: inline-block">
                                        <img src="{{ $media->getUrl('thumb') }}">
                                    </a>
                                @endforeach
                            </td>
                            <td>
                                {{ $content->author->name ?? '' }}
                            </td>
                            <td>
                                {{ $content->status ? trans('vela::global.status_' . $content->status) : '' }}
                            </td>
                            <td>
                                {{ $content->published_at ?? '' }}
                            </td>
                            <td>
                                @can('article_show')
                                    <a class="btn btn-xs btn-primary" href="{{ route('vela.admin.contents.show', $content->id) }}">
                                        {{ trans('vela::global.view') }}
                                    </a>
                                @endcan

                                @can('article_edit')
                                    <a class="btn btn-xs btn-info" href="{{ route('vela.admin.contents.edit', $content->id) }}">
                                        {{ trans('vela::global.edit') }}
                                    </a>
                                @endcan

                                @can('article_delete')
                                    <form action="{{ route('vela.admin.contents.destroy', $content->id) }}" method="POST" onsubmit="return confirm('{{ trans('vela::global.areYouSure') }}');" style="display: inline-block;">
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
@can('article_delete')
  let deleteButtonTrans = '{{ trans('vela::global.datatables.delete') }}'
  let deleteButton = {
    text: deleteButtonTrans,
    url: "{{ route('vela.admin.contents.massDestroy') }}",
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
    order: [[ 2, 'desc' ]],
    pageLength: 100,
  });
  let table = $('.datatable-authorContents:not(.ajaxTable)').DataTable({ buttons: dtButtons })
  $('a[data-toggle="tab"]').on('shown.bs.tab click', function(e){
      $($.fn.dataTable.tables(true)).DataTable()
          .columns.adjust();
  });

})

</script>
@endsection
