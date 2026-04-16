@extends(vela_template_layout())

@section('title', $page->meta_title ?: $page->title)
@section('description', $page->meta_description ?: '')
@if($page->og_image)
    @section('og_image', $page->og_image->url)
@endif

@section('content')
@if($page->slug === 'home')
<div class="page-content page-content--home">
    @include('vela::templates._partials.page-rows', ['page' => $page])
</div>
@else
<div class="mn-page-header">
    <div class="mn-container">
        <h1>{{ $page->title }}</h1>
    </div>
</div>

<div class="mn-section">
    <div class="mn-container">
        <div class="page-content">
            @include('vela::templates._partials.page-rows', ['page' => $page])
        </div>
    </div>
</div>
@endif

@if($page->custom_css)
<style>{!! $page->custom_css !!}</style>
@endif
@if($page->custom_js)
<script>{!! $page->custom_js !!}</script>
@endif
@endsection
