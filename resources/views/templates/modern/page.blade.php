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
<div class="md-page-header">
    <div class="md-container">
        <h1>{{ $page->title }}</h1>
    </div>
</div>
<div class="md-breadcrumb">
    <div class="md-container">
        <a href="{{ route('vela.public.home') }}">{{ __('vela::public.home') }}</a>
        <span>/</span>
        {{ $page->title }}
    </div>
</div>
<section class="md-section">
    <div class="md-container">
        <div class="page-content">
            @include('vela::templates._partials.page-rows', ['page' => $page])
        </div>
    </div>
</section>
@endif

@if($page->custom_css)
<style>{!! $page->custom_css !!}</style>
@endif
@if($page->custom_js)
<script>{!! $page->custom_js !!}</script>
@endif
@endsection
