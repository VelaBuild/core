@php
    // $item is either a MenuItem model or null (template mode).
    // NOTE: decide per-mode, never per-field: a real item may legitimately have
    // a NULL ref_id / url (home, url, posts_index…), and `?? '__REF_ID__'`
    // would leak the JS placeholder into a real row and be posted back.
    $tpl    = $item === null;
    $i      = $index;
    $type   = $tpl ? '__TYPE__'   : $item->type;
    $refId  = $tpl ? '__REF_ID__' : ($item->ref_id ?? '');
    $label  = $tpl ? '__LABEL__'  : ($item->label ?? '');
    $url    = $tpl ? '__URL__'    : ($item->url ?? '');
    $route  = $tpl ? ''           : ($item->route_name ?? '');
    $target = $tpl ? '_self'      : ($item->target ?? '_self');
    $itemId = $tpl ? ''           : $item->id;
    $typeLabel = $tpl ? '__TYPE_LABEL__' : ucwords(str_replace('_', ' ', $item->type));
@endphp
<div class="menu-item-row d-flex align-items-center p-2 mb-2 border rounded bg-light" data-type="{{ $type }}">
    <span class="drag-handle mr-2 text-muted" style="cursor:grab;"><i class="fas fa-grip-vertical"></i></span>

    <input type="hidden" name="items[{{ $i }}][id]"         value="{{ $itemId }}">
    <input type="hidden" name="items[{{ $i }}][type]"       value="{{ $type }}">
    <input type="hidden" name="items[{{ $i }}][ref_id]"     value="{{ $refId }}">
    <input type="hidden" name="items[{{ $i }}][route_name]" value="{{ $route }}">

    <div class="flex-grow-1">
        <div class="d-flex align-items-center" style="gap:.5rem;">
            <span class="badge badge-secondary text-uppercase small" style="font-size:.65rem;">{{ $typeLabel }}</span>
            <input type="text" name="items[{{ $i }}][label]" class="form-control form-control-sm flex-grow-1" value="{{ $label }}" placeholder="{{ __('Label') }}">
        </div>
        {{-- URL field: visible only for `url` type. JS toggles `display`
             based on the row's `data-type` attribute on the wrapper. --}}
        <input type="text" name="items[{{ $i }}][url]" class="form-control form-control-sm mt-1 js-menu-url" value="{{ $url }}" placeholder="https://… or /path" data-show-for="url">

        {{-- Where this item actually goes. A row said "Page" and carried a
             label, and nothing on the screen said WHICH page — so an item
             pointing at a parked homepage (/home-2026-09-04-082328) looked
             exactly like one pointing at the front page, before saving and
             after. The label is what a visitor reads; this is what they get. --}}
        @if(!$tpl && $type !== 'url')
            <div class="small text-muted mt-1 text-truncate" title="{{ $item->resolveUrl() }}">
                <i class="fas fa-link mr-1" style="font-size:.7em;"></i>{{ $item->resolveUrl() }}
            </div>
        @endif
    </div>

    <select name="items[{{ $i }}][target]" class="form-control form-control-sm ml-2" style="width:auto;">
        <option value="_self"  @if($target === '_self')  selected @endif>{{ __('Same tab') }}</option>
        <option value="_blank" @if($target === '_blank') selected @endif>{{ __('New tab') }}</option>
    </select>

    <button type="button" class="btn btn-link btn-sm text-danger ml-1" data-remove>
        <i class="fas fa-times"></i>
    </button>
</div>
