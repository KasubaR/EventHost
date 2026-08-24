<form id="send-verification" method="post" action="{{ route('verification.send') }}">@csrf</form>

@if ($user->profile_photo)
    <form id="remove-photo" method="post" action="{{ route('settings.profile.photo.destroy') }}">
        @csrf
        @method('delete')
    </form>
@endif

<form method="post" action="{{ route('settings.profile.update') }}" enctype="multipart/form-data" class="profile-form">
    @csrf
    @method('patch')

    @if (session('status') === 'profile-updated')
        <div class="profile-success"><i class="fa-solid fa-circle-check"></i> Profile updated successfully.</div>
    @elseif (session('status') === 'photo-removed')
        <div class="profile-success"><i class="fa-solid fa-circle-check"></i> Profile photo removed.</div>
    @endif

    {{-- Photo --}}
    <div class="profile-photo-row">
        <img src="{{ $user->profile_photo_url }}" alt="{{ $user->name }}" width="64" height="64" class="profile-photo-preview" id="photoPreview">
        <div>
            <label for="profile_photo" class="profile-photo-btn">
                <i class="fa-solid fa-camera"></i> Change photo
            </label>
            <input id="profile_photo" name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" class="profile-photo-input" onchange="previewPhoto(this)">
            @if ($user->profile_photo)
                <button type="submit" form="remove-photo" class="profile-photo-remove-btn" data-confirm="Remove your profile photo?">
                    <i class="fa-solid fa-trash"></i> Remove
                </button>
            @endif
            <p class="profile-photo-hint">JPG, PNG or WEBP · Max 2MB · Min 100×100px</p>
            @error('profile_photo')
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
        </div>
    </div>

    <div class="profile-fields">

        {{-- Name --}}
        <div class="profile-field">
            <label for="name" class="profile-label">Full name</label>
            <input id="name" name="name" type="text"
                   class="profile-input {{ $errors->has('name') ? 'profile-input--error' : '' }}"
                   value="{{ old('name', $user->name) }}" required autocomplete="name">
            @error('name')
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
        </div>

        {{-- Email --}}
        <div class="profile-field">
            <label for="email" class="profile-label">Email address</label>
            <input id="email" name="email" type="email"
                   class="profile-input {{ $errors->has('email') ? 'profile-input--error' : '' }}"
                   value="{{ old('email', $user->email) }}" required autocomplete="username">
            @error('email')
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
            @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                <div class="profile-unverified">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    Your email is unverified.
                    <button form="send-verification" class="profile-resend-link">Resend verification email</button>
                    @if (session('status') === 'verification-link-sent')
                        <span class="profile-sent-msg">Verification link sent!</span>
                    @endif
                </div>
            @endif
        </div>

        {{-- Phone --}}
        <div class="profile-field">
            <label for="phone" class="profile-label">Phone number <span class="profile-optional">optional</span></label>
            <input id="phone" name="phone" type="tel"
                   class="profile-input {{ $errors->has('phone') ? 'profile-input--error' : '' }}"
                   value="{{ old('phone', $user->phone) }}" autocomplete="tel" placeholder="+260 97 000 0000">
            @error('phone')
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
        </div>

        {{-- Company --}}
        <div class="profile-field">
            <label for="company_name" class="profile-label">Company / Event business <span class="profile-optional">optional</span></label>
            <input id="company_name" name="company_name" type="text"
                   class="profile-input {{ $errors->has('company_name') ? 'profile-input--error' : '' }}"
                   value="{{ old('company_name', $user->company_name) }}" autocomplete="organization" placeholder="Your business name">
            @error('company_name')
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
        </div>

    </div>

    <div class="profile-form-actions">
        <button type="submit" class="profile-save-btn">
            <i class="fa-solid fa-floppy-disk"></i> Save Changes
        </button>
    </div>

</form>

<script>
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('photoPreview').src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
