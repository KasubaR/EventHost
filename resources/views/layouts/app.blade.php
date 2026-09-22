<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('app.name') }} — {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/logo/EventHost Logo_Icon.svg') }}">

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

{{-- No site header in the portal — the sidebar is the only chrome --}}
<div class="dash-shell">

    {{-- Mobile sidebar toggle --}}
    <button type="button" class="dash-sidebar-toggle" id="sidebarToggle" aria-controls="dashSidebar" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
        <span>Menu</span>
    </button>
    <div class="dash-sidebar-backdrop" id="dashSidebarBackdrop" hidden></div>

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

        @php
            // plans/public-private-portals.md Phase 3: which portal's nav to show.
            // Neither is forced "on" for a page shared by both (Billing, Settings,
            // Reviews) — that's an honest reflection of those pages not belonging
            // to either portal, not a bug to fix.
            // events.create is shared by both portals (Phase 4's chooser) — its
            // route name alone can't say which one, so the in-progress choice
            // (still a query string until the details form makes it a hidden
            // field) breaks the tie for that one route. ?kind=ticketed alone
            // counts too — the public portal's own "New event" links use that
            // shortcut without ?audience= (EventController::resolveCreateAudience()
            // treats them the same way).
            $onPublicCreateStep = request()->routeIs('events.create')
                && (request()->query('audience') === 'public' || request()->query('kind') === 'ticketed');
            $inPublicPortal = request()->routeIs('public-dashboard', 'public-events.*') || $onPublicCreateStep;
            $inPrivatePortal = request()->routeIs('dashboard', 'events.*', 'templates.*') && ! $onPublicCreateStep;
        @endphp

        <nav class="dash-portal-switch" aria-label="Switch portal">
            <a href="{{ route('dashboard') }}" class="dash-portal-switch-tab {{ $inPrivatePortal ? 'is-active' : '' }}">
                <i class="fa-solid fa-envelope-open-text"></i> Private
            </a>
            <a href="{{ route('public-dashboard') }}" class="dash-portal-switch-tab {{ $inPublicPortal ? 'is-active' : '' }}">
                <x-ticket-icon /> Public
            </a>
        </nav>

        <nav class="dash-nav">
            @if ($inPublicPortal)
                <div class="dash-nav-section">
                    <span class="dash-nav-label">Public portal</span>
                    <a href="{{ route('public-dashboard') }}" class="dash-nav-link {{ request()->routeIs('public-dashboard') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-gauge-high"></i> Overview
                    </a>
                    <a href="{{ route('billing.show') }}" class="dash-nav-link {{ request()->routeIs('billing.*', 'payment.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-credit-card"></i> Billing
                    </a>
                    <a href="{{ route('public-events.index') }}" class="dash-nav-link {{ request()->routeIs('public-events.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-envelope-open-text"></i> My Events
                    </a>
                </div>
            @else
                <div class="dash-nav-section">
                    <span class="dash-nav-label">Private portal</span>
                    <a href="{{ route('dashboard') }}" class="dash-nav-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-gauge-high"></i> Overview
                    </a>
                    <a href="{{ route('billing.show') }}" class="dash-nav-link {{ request()->routeIs('billing.*', 'payment.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-credit-card"></i> Billing
                    </a>
                    <a href="{{ route('events.index') }}" class="dash-nav-link {{ request()->routeIs('events.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-envelope-open-text"></i> My Events
                    </a>
                    <a href="{{ route('templates.index') }}" class="dash-nav-link {{ request()->routeIs('templates.*') ? 'is-active' : '' }}">
                        <i class="fa-solid fa-palette"></i> Templates
                    </a>
                </div>
            @endif

            <div class="dash-nav-section">
                <span class="dash-nav-label">Account</span>
                <a href="{{ route('settings.profile.edit') }}" class="dash-nav-link {{ request()->routeIs('settings.*') ? 'is-active' : '' }}">
                    <i class="fa-solid fa-gear"></i> Settings
                </a>
                <a href="{{ route('reviews.index') }}" class="dash-nav-link {{ request()->routeIs('reviews.*') ? 'is-active' : '' }}">
                    <i class="fa-solid fa-star"></i> My Reviews
                </a>
            </div>

            <div class="dash-nav-section">
                <span class="dash-nav-label">Exit</span>
                <a href="{{ url('/') }}" class="dash-nav-link">
                    <i class="fa-solid fa-arrow-left"></i> Back to site
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
<script src="{{ asset('js/nav-progress.js') }}" defer></script>
@stack('scripts')
<script>
    // Mobile sidebar toggle
    document.addEventListener('DOMContentLoaded', () => {
        const toggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('dashSidebar');
        const backdrop = document.getElementById('dashSidebarBackdrop');
        if (!toggle || !sidebar) return;

        const setOpen = (open) => {
            sidebar.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (backdrop) backdrop.hidden = !open;
        };

        toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('is-open')));
        if (backdrop) backdrop.addEventListener('click', () => setOpen(false));

        // Close after picking a destination, and on Escape
        sidebar.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setOpen(false)));
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('is-open')) setOpen(false);
        });
    });
</script>

</body>
</html>
