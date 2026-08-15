{{-- Posts to PUT /password (routes/auth.php). That controller returns back(), which lands on this tab. --}}
<form method="post" action="{{ route('password.update') }}" class="profile-form" id="passwordForm">
    @csrf
    @method('put')

    @if (session('status') === 'password-updated')
        <div class="profile-success"><i class="fa-solid fa-circle-check"></i> Password updated successfully.</div>
    @endif

    <div class="profile-fields">

        <div class="profile-field">
            <label for="current_password" class="profile-label">Current password</label>
            <div class="profile-input-wrap">
                <input id="current_password" name="current_password" type="password"
                       class="profile-input {{ $errors->updatePassword->has('current_password') ? 'profile-input--error' : '' }}"
                       placeholder="Your current password" autocomplete="current-password">
                <button type="button" class="profile-eye" data-target="current_password" aria-label="Toggle visibility">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </div>
            @if ($errors->updatePassword->has('current_password'))
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->updatePassword->first('current_password') }}</span>
            @endif
        </div>

        <div class="profile-field">
            <label for="new_password" class="profile-label">New password</label>
            <div class="profile-input-wrap">
                <input id="new_password" name="password" type="password"
                       class="profile-input {{ $errors->updatePassword->has('password') ? 'profile-input--error' : '' }}"
                       placeholder="Min. 8 characters" autocomplete="new-password">
                <button type="button" class="profile-eye" data-target="new_password" aria-label="Toggle visibility">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </div>
            @if ($errors->updatePassword->has('password'))
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->updatePassword->first('password') }}</span>
            @endif
        </div>

        <div class="profile-field">
            <label for="password_confirmation" class="profile-label">Confirm new password</label>
            <div class="profile-input-wrap">
                <input id="password_confirmation" name="password_confirmation" type="password"
                       class="profile-input {{ $errors->updatePassword->has('password_confirmation') ? 'profile-input--error' : '' }}"
                       placeholder="Repeat new password" autocomplete="new-password">
                <button type="button" class="profile-eye" data-target="password_confirmation" aria-label="Toggle visibility">
                    <i class="fa-solid fa-eye"></i>
                </button>
            </div>
            @if ($errors->updatePassword->has('password_confirmation'))
                <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->updatePassword->first('password_confirmation') }}</span>
            @endif
        </div>

    </div>

    <div class="profile-form-actions">
        <button type="submit" class="profile-save-btn">
            <i class="fa-solid fa-lock"></i> Update Password
        </button>
    </div>

</form>

<script>
// Scoped to this form. A page-wide selector would double-bind any .profile-eye
// that another partial also wires up, and two toggles cancel each other out.
document.querySelectorAll('#passwordForm .profile-eye').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.target);
        if (!input) return;
        const hidden = input.type === 'password';
        input.type = hidden ? 'text' : 'password';
        btn.querySelector('i').className = hidden ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    });
});
</script>
