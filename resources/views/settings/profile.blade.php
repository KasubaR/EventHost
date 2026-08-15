<x-settings-layout :user="$user" heading="Settings" subheading="Manage your personal information, security and preferences.">

    <div class="profile-card">
        <div class="profile-card-header">
            <div class="profile-card-icon" style="background:rgba(30,71,187,0.1)"><i class="fa-solid fa-circle-user" style="color:var(--accent)"></i></div>
            <div>
                <h3>Personal Information</h3>
                <p>Update your name, email, phone and company details.</p>
            </div>
        </div>
        @include('settings.partials.profile-form')
    </div>

</x-settings-layout>
