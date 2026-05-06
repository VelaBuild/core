@php
    // $item is either a MenuItem model or null (template mode).
    $i      = $index;
    $type   = $item->type   ?? '__TYPE__';
    $refId  = $item->ref_id ?? '__REF_ID__';
    $label  = $item->label  ?? '__LABEL__';
    $url    = $item->url    ?? '__URL__';
    $route  = $item->route_name ?? '';
    $target = $item->target ?? '_self';
    $itemId = $item->id     ?? '';
    $typeLabel = $item ? ucwords(str_replace('_', ' ', $item->type)) : '__TYPE_LABEL__';
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
    </div>

    <select name="items[{{ $i }}][target]" class="form-control form-control-sm ml-2" style="width:auto;">
        <option value="_self"  @if($target === '_self')  selected @endif>{{ __('Same tab') }}</option>
        <option value="_blank" @if($target === '_blank') selected @endif>{{ __('New tab') }}</option>
    </select>

    <button type="button" class="btn btn-link btn-sm text-danger ml-1" data-remove>
        <i class="fas fa-times"></i>
    </button>
</div>
