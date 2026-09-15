@php
    $title    = (string) ($item['title'] ?? '');
    $link     = trim((string) ($item['link'] ?? ''));
    // A script URL typed or pasted into a box would run on the click.
    if (preg_match('/^\s*(javascript|data|vbscript):/i', $link)) {
        $link = '';
    }
    $linkText = trim((string) ($item['link_text'] ?? ''));
    // With words the link is a line under the description; without, the
    // whole box is the link — a row of boxes each leading to its page.
    $wholeBox = $link !== '' && $linkText === '';
@endphp
    <div class="icon-box icon-box--{{ $layout }}{{ $wholeBox ? ' icon-box--linked' : '' }}">
        <div class="icon-box-icon">
            <i class="{{ ($item['icon'] ?? '') ?: 'fas fa-star' }}"></i>
        </div>
        <div class="icon-box-words">
@if($title !== '')
@if($wholeBox)
            <p class="icon-box-title"><a href="{{ $link }}" class="icon-box-stretched"{!! vela_external_link_attrs($link) !!}>{{ $title }}</a></p>
@else
            <p class="icon-box-title">{{ $title }}</p>
@endif
@elseif($wholeBox)
            <a href="{{ $link }}" class="icon-box-stretched" aria-label="{{ $link }}"{!! vela_external_link_attrs($link) !!}></a>
@endif
@if(!empty($item['description']))
            <p class="icon-box-description">{{ $item['description'] }}</p>
@endif
@if($link !== '' && $linkText !== '')
            <a href="{{ $link }}" class="icon-box-link"{!! vela_external_link_attrs($link) !!}>{{ $linkText }} <span aria-hidden="true">&rarr;</span></a>
@endif
        </div>
    </div>
