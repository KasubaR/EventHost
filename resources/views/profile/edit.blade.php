<x-app-layout>
    <x-slot name="title">Profile</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <nav class="dash-breadcrumb">
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <i class="fa-solid fa-chevron-right"></i>
                    <span>Profile</span>
                </nav>
                <h1 class="dph-title">Your Profile</h1>
                <p class="dph-sub">Manage your personal information, security and preferences.</p>
            </div>
        </div>
    </x-slot>

    <div class="profile-layout">

        {{-- Profile header card --}}
        <div class="profile-header-card">
            <img src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}" width="72" height="72" class="profile-header-avatar">
            <div class="profile-header-info">
                <h2>{{ $user->name }}</h2>
                <span class="profile-header-email">{{ $user->email }}</span>
                <div class="profile-header-meta">
                    @if($user->company_name)
                        <span><i class="fa-solid fa-building"></i> {{ $user->company_name }}</span>
                    @endif
                    <span><i class="fa-solid fa-calendar-days"></i> Member since {{ $user->created_at->format('M Y') }}</span>
                    <span class="profile-status-badge profile-status-{{ $user->status }}">{{ ucfirst($user->status) }}</span>
                </div>
            </div>
        </div>

        {{-- Personal Information --}}
        <div class="profile-card">
            <div class="profile-card-header">
                <div class="profile-card-icon" style="background:rgba(108,92,231,0.1)"><i class="fa-solid fa-circle-user" style="color:var(--accent)"></i></div>
                <div>
                    <h3>Personal Information</h3>
                    <p>Update your name, email, phone and company details.</p>
                </div>
            </div>
            @include('profile.partials.update-profile-information-form')
        </div>

        {{-- Security --}}
        <div class="profile-card">
            <div class="profile-card-header">
                <div class="profile-card-icon" style="background:rgba(0,206,201,0.1)"><i class="fa-solid fa-lock" style="color:var(--cyan)"></i></div>
                <div>
                    <h3>Security</h3>
                    <p>Keep your account secure with a strong password.</p>
                </div>
            </div>
            @include('profile.partials.update-password-form')
        </div>

        {{-- Notifications --}}
        <div class="profile-card">
            <div class="profile-card-header">
                <div class="profile-card-icon" style="background:rgba(243,156,18,0.1)"><i class="fa-solid fa-bell" style="color:var(--orange)"></i></div>
                <div>
                    <h3>Notification Preferences</h3>
                    <p>Choose which updates you want to receive.</p>
                </div>
            </div>
            @include('profile.partials.notification-preferences-form')
        </div>

        {{-- Danger zone --}}
        <div class="profile-card profile-card--danger">
            <div class="profile-card-header">
                <div class="profile-card-icon" style="background:rgba(232,67,147,0.1)"><i class="fa-solid fa-triangle-exclamation" style="color:var(--pink)"></i></div>
                <div>
                    <h3>Danger Zone</h3>
                    <p>Permanently delete your account and all associated data.</p>
                </div>
            </div>
            @include('profile.partials.delete-user-form')
        </div>

    </div>

</x-app-layout>
