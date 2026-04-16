@extends(vela_template_layout())

@section('title', config('app.name', 'Vela CMS'))

@section('content')
<div style="display: flex; justify-content: center; align-items: center; min-height: 60vh; text-align: center; padding: 2rem;">
    <div>
        <h1 style="font-size: 2.5rem; margin-bottom: 0.5rem;">{{ __('vela::public.welcome_to', ['name' => config('app.name', 'Vela CMS')]) }}</h1>
        <p style="font-size: 1.2rem; opacity: 0.7;">{{ __('vela::public.ready_to_build') }}</p>
    </div>
</div>
@endsection
