@if($row->status !== 'created')
    <button class="btn btn-xs btn-success generate-content-btn" data-idea-id="{{ $row->id }}">
        🤖 Generate
    </button>
@else
    <span class="btn btn-xs btn-secondary disabled">{{ trans('vela::global.generated') }}</span>
@endif
@can($editGate)
    <a class="btn btn-xs btn-info" href="{{ route('vela.admin.' . $crudRoutePart . '.edit', $row->id) }}">
        {{ trans('vela::global.edit') }}
    </a>
@endcan
@can($deleteGate)
    <form action="{{ route('vela.admin.' . $crudRoutePart . '.destroy', $row->id) }}" method="POST" onsubmit="return confirm('{{ trans('vela::global.areYouSure') }}');" style="display: inline-block;">
        <input type="hidden" name="_method" value="DELETE">
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <input type="submit" class="btn btn-xs btn-danger" value="{{ trans('vela::global.delete') }}">
    </form>
@endcan
