<x-settings-layout :user="$user" heading="Settings" subheading="Manage your personal information, security and preferences.">

    <div class="profile-card">
        <div class="profile-card-header">
            <div class="profile-card-icon" style="background:rgba(0,206,201,0.1)"><i class="fa-solid fa-lock" style="color:var(--cyan)"></i></div>
            <div>
                <h3>Security</h3>
                <p>Keep your account secure with a strong password.</p>
            </div>
        </div>
        @include('settings.partials.password-form')
    </div>

</x-settings-layout>
