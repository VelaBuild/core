@php
    // $card from PostsGrid::cards(), $s the block's settings, $variant card |
    // lead | row, $eager for the one picture that should not wait.
    $excerptLimit = $variant === 'lead' ? 220 : ($variant === 'row' ? 160 : 120);
    $meta = array_filter([
        $s['show_date'] && $card['date'] ? $card['date']->format('M j, Y') : null,
        $s['show_author'] && $card['author'] ? trans('vela::global.posts_grid_by', ['name' => $card['author']]) : null,
        $s['show_reading_time'] ? trans('vela::global.posts_grid_min_read', ['count' => $card['minutes']]) : null,
    ]);
    $picture = $s['show_image'] && $card['image'];
    $panel   = $s['show_image'] && !$card['image'] && $withPanels;
@endphp
    <a href="{{ $card['url'] }}" class="post-card post-card--{{ $variant }}{{ $picture ? ' has-image' : '' }}{{ $panel ? ' has-panel' : '' }}">
@if($picture)
        <span class="post-card-media">
            {!! vela_image($card['image']->url, $card['title'], $variant === 'lead' ? [480, 640, 960, 1280] : [320, 480, 640, 960], 'crop', [], $eager ? 'preload' : 'lazy') !!}
        </span>
@elseif($panel)
        {{-- A post with no picture keeps the picture's room, so its title
             lines up with the cards beside it. --}}
        <span class="post-card-media post-card-media--empty" aria-hidden="true"></span>
@endif
        <span class="post-card-body">
@if($s['show_category'] && $card['category'])
            <span class="post-card-category">{{ $card['category'] }}</span>
@endif
            {{-- h2, not h3: a grid placed straight under the page's h1 has no
                 h2 above it to descend from, and h2 stays valid when it does. --}}
            <h2 class="post-card-title">{{ $card['title'] }}</h2>
@if($s['show_excerpt'] && $card['excerpt'] !== '')
            <p class="post-card-excerpt">{{ \Illuminate\Support\Str::limit($card['excerpt'], $excerptLimit) }}</p>
@endif
@if($meta)
            <small class="post-card-meta">{{ implode(' · ', $meta) }}</small>
@endif
        </span>
    </a>
