{{-- Legacy alias — kept so existing template layouts (default / modern /
     editorial / dark / corporate / minimal) continue to work without edit.
     The full tracking stack (GA4 + GTM + Meta Pixel + Google Ads) lives in
     vela::partials.tracking-head, plus the event dispatcher in
     vela::partials.tracking-events. --}}
@include('vela::partials.tracking-head')
@include('vela::partials.tracking-events')
