@props(['user', 'heading', 'subheading'])

@php
    $tabs = [
        ['route' => 'settings.profile.edit',       'pattern' => 'settings.profile.*',       'icon' => 'fa-circle-user',          'label' => 'Profile'],
        ['route' => 'settings.security.edit',      'pattern' => 'settings.security.*',      'icon' => 'fa-lock',                 'label' => 'Security'],
        ['route' => 'settings.notifications.edit', 'pattern' => 'settings.notifications.*', 'icon' => 'fa-bell',                 'label' => 'Notifications'],
        ['route' => 'settings.account.edit',       'pattern' => 'settings.account.*',       'icon' => 'fa-triangle-exclamation', 'label' => 'Account'],
    ];
@endphp

<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/settings.css') }}">
    @endpush

    <x-slot name="title">{{ $heading }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">{{ $heading }}</h1>
                <p class="dph-sub">{{ $subheading }}</p>
            </div>
        </div>
    </x-slot>

    <div class="profile-layout">

        {{-- Identity card — read-only, repeated on every tab so the user keeps their bearings --}}
        <div class="profile-header-card">
            <img src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}" width="72" height="72" class="profile-header-avatar">
            <div class="profile-header-info">
                <h2>{{ $user->name }}</h2>
                <span class="profile-header-email">{{ $user->email }}</span>
                <div class="profile-header-meta">
                    @if ($user->company_name)
                        <span><i class="fa-solid fa-building"></i> {{ $user->company_name }}</span>
                    @endif
                    <span><i class="fa-solid fa-calendar-days"></i> Member since {{ $user->created_at->format('M Y') }}</span>
                    <span class="profile-status-badge profile-status-{{ $user->status }}">{{ ucfirst($user->status) }}</span>
                </div>
            </div>
        </div>

        {{-- global.css styles every bare <nav>; .set-tabs overrides it, same as .dash-nav does --}}
        <nav class="set-tabs" aria-label="Settings sections">
            @foreach ($tabs as $tab)
                <a href="{{ route($tab['route']) }}"
                   class="set-tab {{ request()->routeIs($tab['pattern']) ? 'is-active' : '' }}"
                   @if (request()->routeIs($tab['pattern'])) aria-current="page" @endif>
                    <i class="fa-solid {{ $tab['icon'] }}"></i>
                    <span>{{ $tab['label'] }}</span>
                </a>
            @endforeach
        </nav>

        {{ $slot }}

    </div>
</x-app-layout>
