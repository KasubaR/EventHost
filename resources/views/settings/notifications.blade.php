<x-settings-layout :user="$user" heading="Settings" subheading="Manage your personal information, security and preferences.">

    <div class="profile-card">
        <div class="profile-card-header">
            <div class="profile-card-icon" style="background:rgba(243,156,18,0.1)"><i class="fa-solid fa-bell" style="color:var(--orange)"></i></div>
            <div>
                <h3>Notification Preferences</h3>
                <p>Choose which updates you want to receive.</p>
            </div>
        </div>
        @include('settings.partials.notifications-form')
    </div>

</x-settings-layout>
