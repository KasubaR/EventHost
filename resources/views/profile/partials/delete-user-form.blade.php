<div class="danger-zone">
    <p class="danger-zone-desc">
        Once your account is deleted, all events, invitations, guest data and settings will be <strong>permanently removed</strong>. This cannot be undone.
    </p>

    <button type="button" class="danger-delete-btn" id="openDeleteModal">
        <i class="fa-solid fa-trash"></i> Delete My Account
    </button>
</div>

{{-- Modal --}}
<div class="profile-modal-overlay" id="deleteModalOverlay" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="profile-modal">
        <div class="profile-modal-header">
            <div class="profile-modal-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <h3 id="deleteModalTitle">Delete your account?</h3>
            <p>This will permanently delete all your events, invitations, guests and data. Enter your password to confirm.</p>
        </div>

        <form method="post" action="{{ route('profile.destroy') }}" class="profile-modal-form">
            @csrf
            @method('delete')

            <div class="profile-field">
                <label for="delete_password" class="profile-label">Your password</label>
                <div class="profile-input-wrap">
                    <input id="delete_password" name="password" type="password"
                           class="profile-input {{ $errors->userDeletion->has('password') ? 'profile-input--error' : '' }}"
                           placeholder="Enter your password to confirm">
                    <button type="button" class="profile-eye" data-target="delete_password" aria-label="Toggle visibility">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
                @if ($errors->userDeletion->has('password'))
                    <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->userDeletion->first('password') }}</span>
                @endif
            </div>

            <div class="profile-modal-actions">
                <button type="button" class="profile-modal-cancel" id="closeDeleteModal">Cancel</button>
                <button type="submit" class="danger-confirm-btn">
                    <i class="fa-solid fa-trash"></i> Yes, Delete My Account
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const overlay  = document.getElementById('deleteModalOverlay');
    const openBtn  = document.getElementById('openDeleteModal');
    const closeBtn = document.getElementById('closeDeleteModal');

    function open()  { overlay.classList.add('is-open'); }
    function close() { overlay.classList.remove('is-open'); }

    if (openBtn)  openBtn.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', close);
    if (overlay)  overlay.addEventListener('click', e => { if (e.target === overlay) close(); });

    // Re-open if there are validation errors
    @if ($errors->userDeletion->isNotEmpty())
        open();
    @endif

    // Password eye toggle inside modal
    document.querySelectorAll('.profile-eye').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.target);
            if (!input) return;
            const hidden = input.type === 'password';
            input.type = hidden ? 'text' : 'password';
            btn.querySelector('i').className = hidden ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
        });
    });
}());
</script>
