<x-admin-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Settings</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Platform settings</h1>
                <p class="dph-sub">Site name, sharing copy, and ticket fees used across EventHost.</p>
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'settings-updated')
        <div class="profile-success evt-flash" role="status"><i class="fa-solid fa-circle-check"></i> Settings saved.</div>
    @endif

    @if ($errors->any())
        <div class="profile-errors evt-flash" role="alert"><i class="fa-solid fa-circle-exclamation"></i> {{ $errors->first() }}</div>
    @endif

    <form method="post" action="{{ route('admin.settings.update') }}" class="admin-settings">
        @csrf
        @method('PATCH')

        <div class="profile-card">
            <div class="profile-card-header">
                <div class="profile-card-icon admin-settings-icon"><i class="fa-solid fa-globe" aria-hidden="true"></i></div>
                <div>
                    <h3>Branding</h3>
                    <p>How the platform is named and shared with guests.</p>
                </div>
            </div>
            <div class="profile-fields admin-settings-body">
                <div class="profile-field">
                    <label for="site_name" class="profile-label">Site display name</label>
                    <input id="site_name" type="text" name="site_name"
                           class="profile-input {{ $errors->has('site_name') ? 'profile-input--error' : '' }}"
                           value="{{ old('site_name', $siteName) }}" required maxlength="120">
                    @error('site_name')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>

                <div class="profile-field">
                    <label for="whatsapp_default_message" class="profile-label">
                        Default WhatsApp share message
                        <span class="profile-optional">optional</span>
                    </label>
                    <textarea id="whatsapp_default_message" name="whatsapp_default_message" rows="4" maxlength="2000"
                              class="profile-input {{ $errors->has('whatsapp_default_message') ? 'profile-input--error' : '' }}">{{ old('whatsapp_default_message', $whatsappDefaultMessage) }}</textarea>
                    <p class="admin-muted">Used as a template hint for integrations; delivery still follows channel configuration.</p>
                    @error('whatsapp_default_message')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>
            </div>
        </div>

        <div class="profile-card">
            <div class="profile-card-header">
                <div class="profile-card-icon admin-settings-icon"><i class="fa-solid fa-ticket" aria-hidden="true"></i></div>
                <div>
                    <h3>Ticketing fees</h3>
                    <p>Commission and cancellation rates applied to ticketed events.</p>
                </div>
            </div>
            <div class="profile-fields admin-settings-body">
                <div class="profile-field">
                    <label for="ticketing_commission_percent" class="profile-label">EventHost ticketing commission (%)</label>
                    <input id="ticketing_commission_percent" type="number" name="ticketing_commission_percent"
                           class="profile-input {{ $errors->has('ticketing_commission_percent') ? 'profile-input--error' : '' }}"
                           min="0" max="100" step="0.01" required
                           value="{{ old('ticketing_commission_percent', $ticketingCommissionPercent) }}">
                    <p class="admin-muted">Default 5. Stored on each order at purchase time, so changing this later does not rewrite old sales.</p>
                    @error('ticketing_commission_percent')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>

                <div class="profile-field">
                    <label for="ticketing_cancellation_fee_percent" class="profile-label">Ticketed-event cancellation fee (%)</label>
                    <input id="ticketing_cancellation_fee_percent" type="number" name="ticketing_cancellation_fee_percent"
                           class="profile-input {{ $errors->has('ticketing_cancellation_fee_percent') ? 'profile-input--error' : '' }}"
                           min="0" max="100" step="0.01" required
                           value="{{ old('ticketing_cancellation_fee_percent', $ticketingCancellationFeePercent) }}">
                    <p class="admin-muted">Charged to the organizer if they cancel a ticketed event. Enforcement ships with payouts.</p>
                    @error('ticketing_cancellation_fee_percent')
                        <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                    @enderror
                </div>
            </div>
        </div>

        <div class="admin-settings-actions">
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save settings
            </button>
        </div>
    </form>
</x-admin-layout>
