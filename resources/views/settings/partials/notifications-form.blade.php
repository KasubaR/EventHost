@php
    $prefs = old('notification_preferences', $user->notification_preferences ?? []);
    // 'soon' entries are stored and will be honoured the day the feature
    // behind them ships, but nothing sends against them yet — flagging that
    // here instead of presenting a toggle that silently does nothing.
    $prefLabels = [
        'email_rsvp_updates'    => ['label' => 'RSVP Updates', 'desc' => 'Get notified when guests respond to your invitations', 'icon' => 'fa-envelope'],
        'email_event_reminders' => ['label' => 'Event Reminders', 'desc' => 'Get an email 7 days and 1 day before your events', 'icon' => 'fa-calendar-days'],
        'email_payment_receipts'=> ['label' => 'Payment Receipts', 'desc' => 'Email confirmation for every payment made', 'icon' => 'fa-receipt'],
        'email_marketing'       => ['label' => 'Tips & Announcements', 'desc' => 'Occasional product updates and event hosting tips', 'icon' => 'fa-bullhorn', 'soon' => true],
        'sms_reminders'         => ['label' => 'SMS Reminders', 'desc' => 'Text message reminders sent to your phone', 'icon' => 'fa-mobile-screen-button', 'soon' => true],
        'email_contribution_updates' => ['label' => 'Contribution Updates', 'desc' => 'Get notified when a guest contributes to your event', 'icon' => 'fa-hand-holding-dollar'],
        'push_rsvp_updates'     => ['label' => 'Push RSVP Updates', 'desc' => 'Push notifications on your phone when guests respond', 'icon' => 'fa-bell'],
        'push_event_reminders'  => ['label' => 'Push Event Reminders', 'desc' => 'Push notifications on your phone before your events', 'icon' => 'fa-bell', 'soon' => true],
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
                    <strong>
                        {{ $meta['label'] }}
                        @if (! empty($meta['soon']))
                            <span class="profile-status-badge pref-badge-soon">Coming soon</span>
                        @endif
                    </strong>
                    <span>{{ $meta['desc'] }}</span>
                </div>
                <label class="pref-toggle" aria-label="{{ $meta['label'] }}">
                    <input type="hidden" name="notification_preferences[{{ $key }}]" value="0">
                    {{-- A key missing from the stored JSON (added to
                    DEFAULT_NOTIFICATION_PREFERENCES after this user's account
                    was created) falls back to that preference's own default
                    rather than always reading as off — otherwise an existing
                    user sees a brand-new toggle unchecked even though sends
                    already treat it as on until they touch it. --}}
                    <input type="checkbox" name="notification_preferences[{{ $key }}]" value="1"
                           @checked(array_key_exists($key, $prefs) ? ! empty($prefs[$key]) : $_default)>
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
