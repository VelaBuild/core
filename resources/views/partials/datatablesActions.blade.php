{{-- Row actions: optional "view on site" link, edit, delete.
     Controllers wire the view button by passing $viewUrl (and optionally
     $viewNewTab) into the compact() — see PageController for an example.
     Show routes 302 to edit via VelaRedirectShowToEdit middleware. --}}
@isset($viewUrl)
    @if($viewUrl)
        {{-- $viewIsPreview marks a not-yet-public row: the link goes to the
             admin preview instead of the (404-ing) public URL. --}}
        <a class="btn btn-xs {{ !empty($viewIsPreview) ? 'btn-warning' : 'btn-secondary' }}" href="{{ $viewUrl }}"
           @if(!empty($viewNewTab)) target="_blank" rel="noopener" @endif
           title="{{ !empty($viewIsPreview) ? trans('vela::global.preview') : trans('vela::global.view') }}">
            <i class="fas {{ !empty($viewIsPreview) ? 'fa-search' : 'fa-eye' }}"></i>
        </a>
    @endif
@endisset
{{-- Resources without an edit route (e.g. form-submissions) pass $editGate = null
     and rely on $viewGate to link to the read-only show page instead. --}}
@if(!empty($editGate))
    @can($editGate)
        <a class="btn btn-xs btn-info" href="{{ route('vela.admin.' . $crudRoutePart . '.edit', $row->id) }}">
            {{ trans('vela::global.edit') }}
        </a>
    @endcan
@elseif(!empty($viewGate))
    @can($viewGate)
        <a class="btn btn-xs btn-info" href="{{ route('vela.admin.' . $crudRoutePart . '.show', $row->id) }}">
            {{ trans('vela::global.show') }}
        </a>
    @endcan
@endif
@can($deleteGate)
    <form action="{{ route('vela.admin.' . $crudRoutePart . '.destroy', $row->id) }}" method="POST" onsubmit="return confirm('{{ trans('vela::global.areYouSure') }}');" style="display: inline-block;">
        <input type="hidden" name="_method" value="DELETE">
        <input type="hidden" name="_token" value="{{ csrf_token() }}">
        <input type="submit" class="btn btn-xs btn-danger" value="{{ trans('vela::global.delete') }}">
    </form>
@endcan
