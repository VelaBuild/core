{{-- A single titled form section inside a .vela-edit-page main column. --}}
@props(['title' => '', 'description' => null])
<section {{ $attributes->merge(['class' => 'vela-section']) }}>
@if($title || $description)
    <div class="vela-section-head">
@if($title)<h2>{{ $title }}</h2>@endif
@if($description)<p>{{ $description }}</p>@endif
    </div>
@endif
    <div class="vela-section-body">
        {{ $slot }}
    </div>
</section>
