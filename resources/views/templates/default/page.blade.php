@extends(vela_template_layout())

@section('title', $page->meta_title ?: $page->title)
{{-- Only define the section when there is something to put in it: an
     empty @section still counts as defined, and would suppress the
     site-level fallback in meta-seo. --}}
@if($page->meta_description)
@section('description', $page->meta_description)
@endif
@if($page->og_image)
    @section('og_image', $page->og_image->url)
@endif

@include('vela::templates._partials.hero-preload', ['page' => $page])

@section('content')
<div class="page-content page-slug-{{ $page->slug }} page-id-{{ $page->id }}{{ $page->slug === 'home' ? ' page-content--home' : '' }}">
    @include('vela::templates._partials.page-rows', ['page' => $page])
</div>

@if($page->custom_css)
<style>{!! $page->custom_css !!}</style>
@endif
@if($page->custom_js)
<script>{!! $page->custom_js !!}</script>
@endif
@endsection
