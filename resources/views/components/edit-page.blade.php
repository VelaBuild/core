{{--
    Reusable wrapper for admin edit pages. Gives every CRUD a consistent
    Vela design-system layout: page head with breadcrumb + title + avatar
    + save/cancel, then a 2-column grid with the form's section cards on
    the left and meta/activity cards on the right.

    Usage:

        <x-vela::edit-page
            title="{{ $user->name }}"
            subtitle="{{ $user->email }}"
            :breadcrumb="[
                ['label' => 'Users', 'url' => route('vela.admin.users.index')],
                ['label' => 'Edit'],
            ]"
            avatar="{{ $user->profile_pic->url ?? null }}"
            avatar-fallback="{{ mb_substr($user->name, 0, 1) }}"
            action="{{ route('vela.admin.users.update', $user) }}"
            method="PUT"
            cancel-url="{{ route('vela.admin.users.index') }}"
        >
            <x-slot name="main">
                <x-vela::section title="Identity" description="...">
                    ... form fields ...
                </x-vela::section>
            </x-slot>
            <x-slot name="side">
                <x-vela::meta-card title="Session">
                    ...
                </x-vela::meta-card>
            </x-slot>
        </x-vela::edit-page>
--}}
@props([
    'title' => '',
    'subtitle' => null,
    'breadcrumb' => [],
    'avatar' => null,
    'avatarFallback' => null,
    'action' => null,
    'method' => 'POST',
    'cancelUrl' => null,
    'saveLabel' => null,
    'enctype' => 'multipart/form-data',
])
<div class="vela-edit-page">
    <header class="vela-page-head">
        <div class="vela-page-head-left">
@if(!empty($breadcrumb))
            <div class="vela-breadcrumb">
@foreach($breadcrumb as $i => $crumb)
@if($i > 0)<span class="sep">/</span>@endif
@if(!empty($crumb['url']))<a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
@else<span class="{{ $i === count($breadcrumb) - 1 ? 'cur' : '' }}">{{ $crumb['label'] }}</span>@endif
@endforeach
            </div>
@endif
            <div class="vela-page-title-row">
@if($avatar)
                <img class="vela-page-avatar" src="{{ $avatar }}" alt="">
@elseif($avatarFallback !== null)
                <div class="vela-page-avatar-fallback">{{ mb_strtoupper($avatarFallback) }}</div>
@endif
                <div>
                    <h1>{{ $title }}</h1>
@if($subtitle)
                    <p class="vela-page-sub">{{ $subtitle }}</p>
@endif
                </div>
            </div>
        </div>
        <div class="vela-page-actions">
@if($cancelUrl)
            <a href="{{ $cancelUrl }}" class="btn btn-secondary">Cancel</a>
@endif
@if($action)
            <button type="submit" form="vela-edit-form" class="btn btn-success">{{ $saveLabel ?? 'Save changes' }}</button>
@endif
        </div>
    </header>

@if($action)
    <form id="vela-edit-form" method="POST" action="{{ $action }}" enctype="{{ $enctype }}">
@if(strtoupper($method) !== 'POST') @method($method) @endif
        @csrf
@endif

        <div class="vela-edit-grid {{ empty($side) ? 'no-side' : '' }}">
            <div class="vela-edit-main">
                {{ $main ?? $slot }}
            </div>
@if(!empty($side))
            <aside class="vela-edit-side">
                {{ $side }}
            </aside>
@endif
        </div>

@if($action)
    </form>
@endif
</div>
