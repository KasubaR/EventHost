<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Admin' }} — {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://images.unsplash.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/global.css') }}">
    <link rel="stylesheet" href="{{ asset('css/account-components.css') }}">
    <link rel="stylesheet" href="{{ asset('css/dashboard-shell.css') }}">
    <link rel="stylesheet" href="{{ asset('css/forms-app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin-shell.css') }}">
    <style>
        .dash-shell   { min-height: 100vh; }
        .dash-sidebar { top: 0; height: 100vh; position: sticky; }
        @media (max-width: 900px) {
            .dash-sidebar { top: 0; height: 100vh; }
        }
    </style>
    @stack('styles')
</head>
<body class="dash-body admin-shell">

<div class="dash-shell">

    <aside class="dash-sidebar" id="dashSidebar">

        <div class="dash-user-block admin-user-block">
            <img src="{{ auth('admin')->user()->profile_photo_url }}" alt="{{ auth('admin')->user()->name }}" width="44" height="44" class="dash-user-avatar">
            <div class="dash-user-info">
                <strong>{{ auth('admin')->user()->name }}</strong>
                <span class="admin-role-strip">
                    <span class="admin-role-badge">Admin</span>
                    {{ auth('admin')->user()->email }}
                </span>
            </div>
        </div>

        <nav class="dash-nav admin-dash-nav">
            <div class="dash-nav-section">
                <span class="dash-nav-label">Overview</span>
                <a href="{{ route('admin.dashboard') }}" class="dash-nav-link {{ request()->routeIs('admin.dashboard') ? 'is-active' : '' }}">
                    <i class="fa-solid fa-gauge-high"></i> Dashboard
                </a>
                <a href="{{ route('admin.analytics') }}" class="dash-nav-link {{ request()->routeIs('admin.analytics') ? 'is-active' : '' }}">
                    <i class="fa-solid fa-chart-column"></i> Analytics
                </a>
            </div>

            <div class="dash-nav-section">
                <span class="dash-nav-label">Platform</span>
                @if(auth('admin')->user()?->can('users.view'))
                    <a href="{{ route('admin.users.index') }}" class="dash-nav-link {{ request()->routeIs('admin.users.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-users"></i> Users
                    </a>
                @endif
                @if(auth('admin')->user()?->can('events.view'))
                    <a href="{{ route('admin.events.index') }}" class="dash-nav-link {{ request()->routeIs('admin.events.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-calendar-days"></i> Events
                    </a>
                @endif
                @if(auth('admin')->user()?->can('guests.view'))
                    <a href="{{ route('admin.guests.index') }}" class="dash-nav-link {{ request()->routeIs('admin.guests.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-address-book"></i> Guests
                    </a>
                @endif
                @if(auth('admin')->user()?->can('rsvps.view'))
                    <a href="{{ route('admin.rsvps.index') }}" class="dash-nav-link {{ request()->routeIs('admin.rsvps.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-reply"></i> RSVPs
                    </a>
                @endif
                @if(auth('admin')->user()?->can('notifications.view'))
                    <a href="{{ route('admin.notifications.index') }}" class="dash-nav-link {{ request()->routeIs('admin.notifications.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-paper-plane"></i> Notifications
                    </a>
                @endif
                @if(auth('admin')->user()?->can('payments.view'))
                    <a href="{{ route('admin.payments.index') }}" class="dash-nav-link {{ request()->routeIs('admin.payments.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-credit-card"></i> Payments
                    </a>
                @endif
                @if(auth('admin')->user()?->can('reports.view'))
                    <a href="{{ route('admin.reports.index') }}" class="dash-nav-link {{ request()->routeIs('admin.reports.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-flag"></i> Reports
                    </a>
                @endif
                @if(auth('admin')->user()?->can('settings.manage'))
                    <a href="{{ route('admin.settings.edit') }}" class="dash-nav-link {{ request()->routeIs('admin.settings.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-sliders"></i> Settings
                    </a>
                @endif
            </div>

            <div class="dash-nav-section">
                <span class="dash-nav-label">Exit</span>
                <a href="{{ url('/') }}" class="dash-nav-link">
                    <i class="fa-solid fa-arrow-left"></i> Back to site
                </a>
            </div>
        </nav>

        <form method="POST" action="{{ route('admin.logout') }}" class="dash-sidebar-logout">
            @csrf
            <button type="submit" class="dash-nav-link dash-logout-btn">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign out
            </button>
        </form>

    </aside>

    <main class="dash-main">

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
<script src="{{ asset('js/admin-confirm.js') }}" defer></script>
@stack('scripts')
<script>
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
