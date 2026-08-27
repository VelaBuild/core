{{--
    An article's body, rendered from the editor's own format.

    Articles are stored as EditorJS blocks, not HTML, so a theme that prints
    translated_content gets a page of raw JSON. Every shipped theme carries
    its own copy of this switch; this is the shared one, so a theme written
    from now on needs a single include and a new block type has one place to
    be taught.

    Expects: $post
--}}
@php
    $velaContent = is_string($post->translated_content)
        ? json_decode($post->translated_content, true)
        : $post->translated_content;
@endphp

@if(!empty($velaContent['blocks']) && is_array($velaContent['blocks']))
    @foreach($velaContent['blocks'] as $velaBlock)
        @switch($velaBlock['type'] ?? '')
            @case('paragraph')
                <p>{!! renderMarkdown($velaBlock['data']['text'] ?? '') !!}</p>
                @break

            @case('header')
                @php
                    $velaLevel = (int) ($velaBlock['data']['level'] ?? 2);
                    $velaTag = 'h' . max(2, min(6, $velaLevel));
                @endphp
                <{{ $velaTag }}>{!! renderMarkdown($velaBlock['data']['text'] ?? '') !!}</{{ $velaTag }}>
                @break

            @case('list')
                @php
                    $velaOrdered = ($velaBlock['data']['style'] ?? '') === 'ordered';
                @endphp
                @if(!empty($velaBlock['data']['items']) && is_array($velaBlock['data']['items']))
                    <{{ $velaOrdered ? 'ol' : 'ul' }}>
                        @foreach($velaBlock['data']['items'] as $velaItem)
                            {{-- A nested list arrives as an array with its own content --}}
                            <li>{!! renderMarkdown(is_array($velaItem) ? ($velaItem['content'] ?? '') : $velaItem) !!}</li>
                        @endforeach
                    </{{ $velaOrdered ? 'ol' : 'ul' }}>
                @endif
                @break

            @case('image')
                @if(!empty($velaBlock['data']['file']['url']))
                    <figure>
                        {!! vela_image(
                            $velaBlock['data']['file']['url'],
                            $velaBlock['data']['caption'] ?? '',
                            [400, 800, 1200]
                        ) !!}
                        @if(!empty($velaBlock['data']['caption']))
                            <figcaption>{{ $velaBlock['data']['caption'] }}</figcaption>
                        @endif
                    </figure>
                @endif
                @break

            @case('quote')
                <blockquote>
                    {!! renderMarkdown($velaBlock['data']['text'] ?? '') !!}
                    @if(!empty($velaBlock['data']['caption']))
                        <cite>{{ $velaBlock['data']['caption'] }}</cite>
                    @endif
                </blockquote>
                @break

            @case('code')
                <pre><code>{{ $velaBlock['data']['code'] ?? '' }}</code></pre>
                @break

            @case('table')
                @php
                    $velaRows = $velaBlock['data']['content'] ?? [];
                    $velaHeadings = !empty($velaBlock['data']['withHeadings']);
                @endphp
                @if(is_array($velaRows) && count($velaRows))
                    <div class="table-scroll">
                        <table>
                            @if($velaHeadings)
                                <thead><tr>
                                    @foreach((array) array_shift($velaRows) as $velaCell)
                                        <th>{!! $velaCell !!}</th>
                                    @endforeach
                                </tr></thead>
                            @endif
                            <tbody>
                                @foreach($velaRows as $velaRow)
                                    <tr>@foreach((array) $velaRow as $velaCell)<td>{!! $velaCell !!}</td>@endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                @break

            @case('delimiter')
                <hr>
                @break

            @default
                @if(isset($velaBlock['data']['text']))
                    <p>{!! renderMarkdown($velaBlock['data']['text']) !!}</p>
                @endif
        @endswitch
    @endforeach
@elseif(is_string($post->translated_content) && trim($post->translated_content) !== '')
    {{-- Written before the editor stored blocks, or imported as plain HTML --}}
    {!! $post->translated_content !!}
@endif
