{{-- Row actions: optional "view on site" link, edit, delete.
     Controllers wire the view button by passing $viewUrl (and optionally
     $viewNewTab) into the compact() — see PageController for an example.
     Show routes 302 to edit via VelaRedirectShowToEdit middleware. --}}
@isset($viewUrl)
    @if($viewUrl)
        <a class="btn btn-xs btn-secondary" href="{{ $viewUrl }}"
           @if(!empty($viewNewTab)) target="_blank" rel="noopener" @endif
           title="{{ trans('vela::global.view') }}">
            <i class="fas fa-eye"></i>
        </a>
    @endif
@endisset
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
