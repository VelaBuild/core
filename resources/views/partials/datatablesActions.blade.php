{{-- Single action per row: edit. There is no separate "view" path.
     Show routes 302 to edit via VelaRedirectShowToEdit middleware; if
     a user lacks edit permission they see no row-level action at all
     (they shouldn't be on the list page in the first place if they
     can't act on it). --}}
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
