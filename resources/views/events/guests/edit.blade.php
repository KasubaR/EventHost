<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/forms-app.css') }}">
    @endpush

    <x-slot name="title">Edit guest — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Edit guest</h1>
                <p class="dph-sub">{{ $guest->name }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.guests.index', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-list"></i> All guests</a>
            </div>
        </div>
    </x-slot>

    <div class="evt-stack">
        <div class="evt-section">
            <div class="evt-section-body profile-card-like">
                <form method="post" action="{{ route('events.guests.update', ['event' => $event, 'guest' => $guest->id]) }}" class="profile-form-stack">
                    @csrf
                    @method('PATCH')
                    <div class="profile-field">
                        <label for="guest_name" class="profile-label">Name</label>
                        <input id="guest_name" type="text" name="name" class="profile-input {{ $errors->has('name') ? 'profile-input--error' : '' }}" value="{{ old('name', $guest->name) }}" required maxlength="255">
                        @error('name')
                            <p class="profile-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="profile-field">
                        <label for="guest_email" class="profile-label">Email <span class="profile-optional">optional</span></label>
                        <input id="guest_email" type="email" name="email" class="profile-input {{ $errors->has('email') ? 'profile-input--error' : '' }}" value="{{ old('email', $guest->email) }}" maxlength="255" autocomplete="email">
                        @error('email')
                            <p class="profile-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="profile-field">
                        <label for="guest_phone" class="profile-label">Phone <span class="profile-optional">optional</span></label>
                        <input id="guest_phone" type="tel" name="phone" class="profile-input {{ $errors->has('phone') ? 'profile-input--error' : '' }}" value="{{ old('phone', $guest->phone) }}" maxlength="50" autocomplete="tel">
                        @error('phone')
                            <p class="profile-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="profile-field">
                        <label for="guest_group_id" class="profile-label">Group <span class="profile-optional">optional</span></label>
                        <select id="guest_group_id" name="guest_group_id" class="profile-input {{ $errors->has('guest_group_id') ? 'profile-input--error' : '' }}" aria-label="Guest group">
                            <option value="">— None —</option>
                            @foreach ($groups as $g)
                                <option value="{{ $g->id }}" @selected(old('guest_group_id', $guest->guest_group_id) == $g->id)>{{ $g->name }}</option>
                            @endforeach
                        </select>
                        @error('guest_group_id')
                            <p class="profile-error">{{ $message }}</p>
                        @enderror
                        <p class="evt-muted"><a href="{{ route('events.guest-groups.index', $event) }}">Manage groups</a></p>
                    </div>
                    <div class="profile-field">
                        <input type="hidden" name="plus_one_allowed" value="0">
                        <label class="profile-label evt-check-label">
                            <input type="checkbox" name="plus_one_allowed" value="1" class="profile-input evt-check-input" @checked(old('plus_one_allowed', $guest->plus_one_allowed))>
                            <span>Allow plus-one for this guest</span>
                        </label>
                        @error('plus_one_allowed')
                            <p class="profile-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="profile-field">
                        <input type="hidden" name="regenerate_invitation_token" value="0">
                        <label class="profile-label evt-check-label">
                            <input type="checkbox" name="regenerate_invitation_token" value="1" class="profile-input evt-check-input" @checked(old('regenerate_invitation_token'))>
                            <span>Generate a new personal RSVP link (invalidates the old link)</span>
                        </label>
                        @error('regenerate_invitation_token')
                            <p class="profile-error">{{ $message }}</p>
                        @enderror
                    </div>
                    @if ($guest->invitation_token)
                        <p class="evt-muted evt-word-break">Current link: {{ route('rsvp.token.show', ['token' => $guest->invitation_token]) }}</p>
                    @endif
                    <div class="profile-field">
                        <label class="profile-label evt-check-label">
                            <input type="checkbox" name="mark_invitation_sent" value="1" class="profile-input evt-check-input" @checked(old('mark_invitation_sent'))>
                            <span>Mark invitation as sent now</span>
                        </label>
                        @if ($guest->invitation_sent && $guest->invitation_sent_at)
                            <p class="evt-muted">Currently marked sent {{ $guest->invitation_sent_at->timezone(config('app.timezone'))->format('M j, Y \a\t g:i A') }}.</p>
                        @endif
                        @error('mark_invitation_sent')
                            <p class="profile-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="profile-actions">
                        <button type="submit" class="btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
