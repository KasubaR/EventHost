<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/custom-select.js') }}" defer></script>
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
            <div class="evt-section-body">
                <form method="post" action="{{ route('events.guests.update', ['event' => $event, 'guest' => $guest->id]) }}" class="profile-fields">
                    @csrf
                    @method('PATCH')
                    <div class="profile-field">
                        <label for="guest_name" class="profile-label">Name</label>
                        <input id="guest_name" type="text" name="name" class="profile-input {{ $errors->has('name') ? 'profile-input--error' : '' }}" value="{{ old('name', $guest->name) }}" required maxlength="255">
                        @error('name')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                    </div>
                    <div class="profile-field">
                        <label for="guest_email" class="profile-label">Email <span class="profile-optional">optional</span></label>
                        <input id="guest_email" type="email" name="email" class="profile-input {{ $errors->has('email') ? 'profile-input--error' : '' }}" value="{{ old('email', $guest->email) }}" maxlength="255" autocomplete="email">
                        @error('email')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                    </div>
                    <div class="profile-field">
                        <label for="guest_phone" class="profile-label">Phone <span class="profile-optional">optional</span></label>
                        <input id="guest_phone" type="tel" name="phone" class="profile-input {{ $errors->has('phone') ? 'profile-input--error' : '' }}" value="{{ old('phone', $guest->phone) }}" maxlength="50" autocomplete="tel">
                        @error('phone')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                    </div>
                    <div class="profile-field">
                        <label for="guest_group_id" class="profile-label">Group <span class="profile-optional">optional</span></label>
                        <select id="guest_group_id" name="guest_group_id" data-cs data-cs-icon="fa-solid fa-people-group" class="profile-input {{ $errors->has('guest_group_id') ? 'profile-input--error' : '' }}" aria-label="Guest group">
                            <option value="">— None —</option>
                            @foreach ($groups as $g)
                                <option value="{{ $g->id }}" @selected(old('guest_group_id', $guest->guest_group_id) == $g->id)>{{ $g->name }}</option>
                            @endforeach
                        </select>
                        @error('guest_group_id')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                        <a href="{{ route('events.guest-groups.index', $event) }}" class="evt-btn-outline evt-btn-tiny"><i class="fa-solid fa-people-group"></i> Manage groups</a>
                    </div>
                    <div class="profile-field">
                        <label for="event_table_id" class="profile-label">Table <span class="profile-optional">optional</span></label>
                        <select id="event_table_id" name="event_table_id" data-cs data-cs-icon="fa-solid fa-chair" class="profile-input {{ $errors->has('event_table_id') ? 'profile-input--error' : '' }}" aria-label="Seating table">
                            <option value="">— Unassigned —</option>
                            @foreach ($tables as $t)
                                <option value="{{ $t->id }}" @selected(old('event_table_id', $guest->event_table_id) == $t->id)>{{ $t->label }}</option>
                            @endforeach
                        </select>
                        @error('event_table_id')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                        <a href="{{ route('events.tables.index', $event) }}" class="evt-btn-outline evt-btn-tiny"><i class="fa-solid fa-chair"></i> Manage tables</a>
                    </div>
                    <div class="profile-field">
                        <input type="hidden" name="plus_one_allowed" value="0">
                        <label class="profile-label evt-check-label">
                            <input type="checkbox" name="plus_one_allowed" value="1" class="profile-input evt-check-input" @checked(old('plus_one_allowed', $guest->plus_one_allowed))>
                            <span>Allow plus-one for this guest</span>
                        </label>
                        @error('plus_one_allowed')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                    </div>
                    <div class="profile-field">
                        <input type="hidden" name="regenerate_invitation_token" value="0">
                        <label class="profile-label evt-check-label">
                            <input type="checkbox" name="regenerate_invitation_token" value="1" class="profile-input evt-check-input" @checked(old('regenerate_invitation_token'))>
                            <span>Generate a new personal RSVP link (invalidates the old link)</span>
                        </label>
                        @error('regenerate_invitation_token')
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
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
                            <span class="profile-field-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
                        @enderror
                    </div>
                    <div class="profile-form-actions">
                        <button type="submit" class="btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
