@php
    $content = $block->content;
    $blocks = $content['blocks'] ?? [];
@endphp
@if(count($blocks) > 0)
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
        @elseif($editorBlock['type'] === 'quote')
            <blockquote>{!! $editorBlock['data']['text'] ?? '' !!}</blockquote>
        @elseif($editorBlock['type'] === 'table')
            <table>
                @foreach($editorBlock['data']['content'] ?? [] as $tableRow)
                    <tr>@foreach($tableRow as $cell)<td>{!! $cell !!}</td>@endforeach</tr>
                @endforeach
            </table>
        @elseif($editorBlock['type'] === 'image')
            <figure>
                <img src="{{ $editorBlock['data']['file']['url'] ?? '' }}" alt="{{ $editorBlock['data']['caption'] ?? '' }}" style="max-height:150px;">
                @if(!empty($editorBlock['data']['caption']))
                    <figcaption><small>{{ $editorBlock['data']['caption'] }}</small></figcaption>
                @endif
            </figure>
        @endif
    @endforeach
@else
    <em class="text-muted">{{ trans('vela::global.empty_text_block') }}</em>
@endif
