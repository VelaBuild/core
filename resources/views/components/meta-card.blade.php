{{-- Small card for the right-column meta sidebar — read-only info,
     per-item preferences, activity lists. Optional status dot. --}}
@props(['title' => '', 'status' => null, 'bodyPadding' => true])
<section {{ $attributes->merge(['class' => 'vela-meta-card']) }}>
@if($title || $status !== null)
    <div class="vela-meta-head">
@if($title)<h3>{{ $title }}</h3>@endif
@if($status !== null)
        <span class="dot {{ $status ? 'on' : '' }}" title="{{ $status ? 'Active' : 'Inactive' }}"></span>
@endif
    </div>
@endif
@if($bodyPadding)
    <div class="vela-meta-body">{{ $slot }}</div>
@else
    {{ $slot }}
@endif
</section>
