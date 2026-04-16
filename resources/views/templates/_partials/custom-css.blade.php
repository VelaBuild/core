@php $__siteCss = config('vela.site.custom_css_global', ''); @endphp
@if($__siteCss)<style id="vela-site-css">{!! $__siteCss !!}</style>@endif
@if(!empty($page->custom_css ?? null))<style id="vela-page-css">{!! $page->custom_css !!}</style>@endif
