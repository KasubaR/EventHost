<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name') }} — {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://images.unsplash.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/global.css') }}">
    <link rel="stylesheet" href="{{ asset('css/account-components.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard-shell.css') }}">
    <link rel="stylesheet" href="{{ asset('css/forms-app.css') }}">
    @stack('styles')
</head>
<body class="dash-body">

{{-- Top Nav --}}
<x-site-header />

<div class="dash-shell">

    {{-- Sidebar --}}
    <aside class="dash-sidebar" id="dashSidebar">

        {{-- User avatar block --}}
        <div class="dash-user-block">
            <img src="{{ auth()->user()->profile_photo_url }}" alt="{{ auth()->user()->name }}" width="44" height="44" class="dash-user-avatar">
            <div class="dash-user-info">
                <strong>{{ auth()->user()->name }}</strong>
                <span>{{ auth()->user()->email }}</span>
            </div>
        </div>

        <nav class="dash-nav">
            <div class="dash-nav-section">
                <span class="dash-nav-label">Main</span>
                <a href="{{ route('dashboard') }}" class="dash-nav-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
                    <i class="fa-solid fa-gauge-high"></i> Overview
                </a>
                <a href="{{ route('events.index') }}" class="dash-nav-link {{ request()->routeIs('events.*') ? 'is-active' : '' }}">
                    <i class="fa-solid fa-envelope-open-text"></i> My Events
                </a>
                <a href="{{ route('templates.index') }}" class="dash-nav-link {{ request()->routeIs('templates.*') ? 'is-active' : '' }}">
                    <i class="fa-solid fa-palette"></i> Templates
                </a>
            </div>

            <div class="dash-nav-section">
                <span class="dash-nav-label">Account</span>
                <a href="{{ route('profile.edit') }}" class="dash-nav-link {{ request()->routeIs('profile.*') ? 'is-active' : '' }}">
                    <i class="fa-solid fa-circle-user"></i> Profile
                </a>
                <a href="#" class="dash-nav-link">
                    <i class="fa-solid fa-gear"></i> Settings
                    <span class="dash-nav-soon">Soon</span>
                </a>
            </div>
        </nav>

        <form method="POST" action="{{ route('logout') }}" class="dash-sidebar-logout">
            @csrf
            <button type="submit" class="dash-nav-link dash-logout-btn">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out
            </button>
        </form>

    </aside>

    {{-- Main content --}}
    <main class="dash-main">

        {{-- Page header --}}
        @isset($pageHeader)
            <div class="dash-page-header">
                {{ $pageHeader }}
            </div>
        @endisset

        <div class="dash-content">
            {{ $slot }}
        </div>

    </main>

</div>

<script src="{{ asset('js/homepage.js') }}" defer></script>
@stack('scripts')
<script>
    // Mobile sidebar toggle
    document.addEventListener('DOMContentLoaded', () => {
        const toggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('dashSidebar');
        if (toggle && sidebar) {
            toggle.addEventListener('click', () => sidebar.classList.toggle('is-open'));
        }
    });
</script>

</body>
</html>
