@php
    $testimonials = ($block->content)['testimonials'] ?? [];
@endphp
@if(count($testimonials) > 0)
    @foreach($testimonials as $t)
        @if(!empty($t['quote']) || !empty($t['name']))
        <div style="border-left:3px solid #dee2e6; padding:6px 12px; margin-bottom:6px;">
            @if(!empty($t['quote']))
                <p style="margin:0 0 4px; font-style:italic; color:#495057;">&ldquo;{{ \Illuminate\Support\Str::limit($t['quote'], 150) }}&rdquo;</p>
            @endif
            <small class="text-muted">
                @if(!empty($t['name']))&mdash; {{ $t['name'] }}@endif
                @if(!empty($t['title'])), {{ $t['title'] }}@endif
            </small>
        </div>
        @endif
    @endforeach
@else
    <em class="text-muted">{{ trans('vela::global.empty_block') }}</em>
@endif
