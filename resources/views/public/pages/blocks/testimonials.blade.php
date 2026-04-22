@php
    $testimonials = ($block->content)['testimonials'] ?? [];
@endphp
@if(count($testimonials) > 0)
<div class="block-testimonials">
@foreach($testimonials as $t)
@if(!empty($t['quote']) || !empty($t['name']))
    <div class="testimonial-card">
        <div class="testimonial-quote">
            <div class="testimonial-quote-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.638-3.996 5.849h3.983v10h-9.983z"/>
                </svg>
            </div>
            <p>{{ $t['quote'] ?? '' }}</p>
        </div>
        <div class="testimonial-author">
@if(!empty($t['photo_url']))
            {!! vela_image($t['photo_url'], $t['name'] ?? '', [96, 192], 'crop', ['class' => 'testimonial-photo']) !!}
@endif
            <div>
@if(!empty($t['name']))
                <div class="testimonial-name">{{ $t['name'] }}</div>
@endif
@if(!empty($t['title']))
                <div class="testimonial-title">{{ $t['title'] }}</div>
@endif
            </div>
        </div>
    </div>
@endif
@endforeach
</div>
@else
    @include('vela::public.pages.blocks._empty_state', [
        'icon'    => 'fa-comment-dots',
        'title'   => trans('vela::global.testimonials_empty_title'),
        'message' => trans('vela::global.testimonials_empty_message'),
    ])
@endif
