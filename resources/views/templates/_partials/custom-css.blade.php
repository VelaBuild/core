@php $__siteCss = config('vela.site.custom_css_global', ''); @endphp
@if($__siteCss)<style id="vela-site-css">{!! $__siteCss !!}</style>@endif
@if(!empty($page->custom_css ?? null))<style id="vela-page-css">{!! $page->custom_css !!}</style>@endif
@if($holdingPageActive ?? false)
    <style id="vela-holding-css">
        /* Holding page: hide menu links but keep header/footer structure.
           Logo links (containing <img>) are preserved via :has(). */
        body > nav a:not(:has(img)),
        body > nav button,
        body > nav [x-data],
        body > header a:not(:has(img)),
        body > header button,
        body > header nav,
        body > header [x-data],
        body > div[class*="topbar"],
        footer ul,
        footer nav,
        footer h4 { display: none !important; }
    </style>
@endif
