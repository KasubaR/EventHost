@php
    $prefs = old('notification_preferences', $user->notification_preferences ?? []);
    $prefLabels = [
        'email_rsvp_updates'    => ['label' => 'RSVP updates', 'desc' => 'Get notified when guests respond to your invitations', 'icon' => 'fa-envelope'],
        'email_event_reminders' => ['label' => 'Event reminders', 'desc' => 'Receive reminders before your events go live', 'icon' => 'fa-calendar-days'],
        'email_payment_receipts'=> ['label' => 'Payment receipts', 'desc' => 'Email confirmation for every payment made', 'icon' => 'fa-receipt'],
        'email_marketing'       => ['label' => 'Tips & announcements', 'desc' => 'Occasional product updates and event hosting tips', 'icon' => 'fa-bullhorn'],
        'sms_reminders'         => ['label' => 'SMS reminders', 'desc' => 'Text message reminders sent to your phone', 'icon' => 'fa-mobile-screen-button'],
    ];
@endphp

<form method="post" action="{{ route('settings.notifications.update') }}" class="profile-form">
    @csrf
    @method('patch')

    @if (session('status') === 'preferences-updated')
        <div class="profile-success"><i class="fa-solid fa-circle-check"></i> Preferences saved.</div>
    @endif

    <div class="pref-list">
        @foreach (\App\Models\User::defaultNotificationPreferences() as $key => $_default)
            @php $meta = $prefLabels[$key] ?? ['label' => $key, 'desc' => '', 'icon' => 'fa-bell']; @endphp
            <div class="pref-row">
                <div class="pref-icon"><i class="fa-solid {{ $meta['icon'] }}"></i></div>
                <div class="pref-text">
                    <strong>{{ $meta['label'] }}</strong>
                    <span>{{ $meta['desc'] }}</span>
                </div>
                <label class="pref-toggle" aria-label="{{ $meta['label'] }}">
                    <input type="hidden" name="notification_preferences[{{ $key }}]" value="0">
                    <input type="checkbox" name="notification_preferences[{{ $key }}]" value="1" @checked(!empty($prefs[$key]))>
                    <span class="pref-toggle-track"><span class="pref-toggle-thumb"></span></span>
                </label>
            </div>
        @endforeach
    </div>

    <div class="profile-form-actions">
        <button type="submit" class="profile-save-btn">
            <i class="fa-solid fa-floppy-disk"></i> Save Preferences
        </button>
    </div>

</form>
