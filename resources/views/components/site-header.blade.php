<nav>
    <a href="{{ url('/') }}" class="nav-logo">
        <div class="logo-icon-eq" aria-hidden="true">
            <span></span><span></span><span></span><span></span><span></span>
        </div>
        Event <span>Host</span>
    </a>

    <div class="nav-links">
        <a href="{{ url('/#hero') }}">Home</a>
        <a href="{{ url('/#why') }}">About Us</a>
        <a href="{{ url('/#contact') }}">Contact Us</a>
    </div>

    <div class="nav-actions nav-actions-group">
        @auth
            <a href="{{ route('dashboard') }}" class="nav-user-link {{ request()->routeIs('dashboard') ? 'is-active' : '' }}">Dashboard</a>
            <a href="{{ route('profile.edit') }}" class="nav-user-link {{ request()->routeIs('profile.edit') ? 'is-active' : '' }}">Profile</a>
            <form method="POST" action="{{ route('logout') }}" class="nav-logout-form">
                @csrf
                <button type="submit" class="btn-ghost">{{ __('Log out') }}</button>
            </form>
        @else
            <a href="{{ route('login') }}" class="btn-ghost">{{ __('Sign in') }}</a>
            <a href="{{ route('register') }}" class="btn-primary">{{ __('Sign up') }}</a>
        @endauth
    </div>

    <button class="nav-hamburger" aria-label="Open menu" aria-expanded="false" aria-controls="nav-mobile-menu">
        <span></span><span></span><span></span>
    </button>

    <div class="nav-mobile-menu" id="nav-mobile-menu" hidden>
        <div class="nav-mobile-links">
            <a href="{{ url('/#hero') }}">Home</a>
            <a href="{{ url('/#why') }}">About Us</a>
            <a href="{{ url('/#contact') }}">Contact Us</a>
        </div>
        <div class="nav-mobile-actions">
            @auth
                <a href="{{ route('dashboard') }}" class="nav-user-link">Dashboard</a>
                <a href="{{ route('profile.edit') }}" class="nav-user-link">Profile</a>
                <form method="POST" action="{{ route('logout') }}" class="nav-logout-form">
                    @csrf
                    <button type="submit" class="btn-ghost">{{ __('Log out') }}</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="btn-ghost">{{ __('Sign in') }}</a>
                <a href="{{ route('register') }}" class="btn-primary">{{ __('Sign up') }}</a>
            @endauth
        </div>
    </div>
</nav>
