<x-settings-layout :user="$user" heading="Settings" subheading="Manage your personal information, security and preferences.">

    <div class="profile-card profile-card--danger">
        <div class="profile-card-header">
            <div class="profile-card-icon" style="background:rgba(224,14,79,0.1)"><i class="fa-solid fa-triangle-exclamation" style="color:var(--pink)"></i></div>
            <div>
                <h3>Danger Zone</h3>
                <p>Permanently delete your account and all associated data.</p>
            </div>
        </div>
        @include('settings.partials.delete-account-form')
    </div>

</x-settings-layout>
