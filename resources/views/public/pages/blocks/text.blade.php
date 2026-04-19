@php
    $content = $block->content;
    $blocks = $content['blocks'] ?? [];
@endphp
<div class="block-text">
@foreach($blocks as $editorBlock)
@if($editorBlock['type'] === 'paragraph')
    <p>{!! $editorBlock['data']['text'] ?? '' !!}</p>
@elseif($editorBlock['type'] === 'header')
        <h{{ $editorBlock['data']['level'] ?? 2 }}>{!! $editorBlock['data']['text'] ?? '' !!}</h{{ $editorBlock['data']['level'] ?? 2 }}>
@elseif($editorBlock['type'] === 'list')
@if(($editorBlock['data']['style'] ?? 'unordered') === 'ordered')
            <ol>@foreach($editorBlock['data']['items'] ?? [] as $item)<li>{!! $item !!}</li>@endforeach</ol>
@else
                <ul>@foreach($editorBlock['data']['items'] ?? [] as $item)<li>{!! $item !!}</li>@endforeach</ul>
@endif
@elseif($editorBlock['type'] === 'checklist')
            <div class="block-checklist">
@foreach($editorBlock['data']['items'] ?? [] as $checkItem)
                <div class="checklist-item{{ !empty($checkItem['checked']) ? ' checklist-item--checked' : '' }}">
                    <span class="checklist-checkbox">{!! !empty($checkItem['checked']) ? '&#9745;' : '&#9744;' !!}</span>
                    <span>{!! $checkItem['text'] ?? '' !!}</span>
                </div>
@endforeach
            </div>
@elseif($editorBlock['type'] === 'quote')
            <blockquote>{!! $editorBlock['data']['text'] ?? '' !!}
@if(!empty($editorBlock['data']['caption']))
                <cite>{!! $editorBlock['data']['caption'] !!}</cite>
@endif
            </blockquote>
@elseif($editorBlock['type'] === 'warning')
            <div class="block-warning">
                <div class="block-warning-title">{!! $editorBlock['data']['title'] ?? '' !!}</div>
                <div class="block-warning-message">{!! $editorBlock['data']['message'] ?? '' !!}</div>
            </div>
@elseif($editorBlock['type'] === 'delimiter')
            <hr class="block-delimiter">
@elseif($editorBlock['type'] === 'table')
            <table>
@foreach($editorBlock['data']['content'] ?? [] as $tableRow)
                <tr>@foreach($tableRow as $cell)<td>{!! $cell !!}</td>@endforeach</tr>
@endforeach
            </table>
@elseif($editorBlock['type'] === 'image')
            <figure>
                {!! vela_image($editorBlock['data']['file']['url'] ?? '', $editorBlock['data']['caption'] ?? '', [640, 960, 1280, 1920]) !!}
@if(!empty($editorBlock['data']['caption']))
                <figcaption>{{ $editorBlock['data']['caption'] }}</figcaption>
@endif
            </figure>
@endif
@endforeach
</div>
