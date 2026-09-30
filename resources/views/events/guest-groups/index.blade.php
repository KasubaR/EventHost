<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Guest groups — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Guest groups</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.guests.index', $event) }}" class="btn-primary"><i class="fa-solid fa-users"></i> Guests</a>
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Event</a>
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'guest-group-created')
        <div class="evt-admin-flash">Group added.</div>
    @elseif (session('status') === 'guest-group-updated')
        <div class="evt-admin-flash">Group updated.</div>
    @elseif (session('status') === 'guest-group-deleted')
        <div class="evt-admin-flash">Group removed.</div>
    @elseif (session('status') === 'guest-group-link-saved')
        <div class="evt-admin-flash">Group link saved.</div>
    @elseif (session('status') === 'guest-group-link-closed')
        <div class="evt-admin-flash">Group link closed. Nobody can request new seats until you reopen it.</div>
    @elseif (session('status') === 'guest-group-link-reopened')
        <div class="evt-admin-flash">Group link reopened.</div>
    @elseif (session('status') === 'guest-group-link-removed')
        <div class="evt-admin-flash">Group link turned off. Its old address no longer works.</div>
    @endif

    <div class="evt-stack">
        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Add group</h2>
                <p>Use groups to organize imports and filters (Family, Friends, Work, etc.).</p>
            </div>
            <div class="evt-section-body profile-card-like">
                <form method="post" action="{{ route('events.guest-groups.store', $event) }}" class="profile-form-stack evt-guest-group-inline">
                    @csrf
                    <div class="profile-field evt-guest-group-inline-field">
                        <label for="new_group_name" class="profile-label">Group name</label>
                        <input id="new_group_name" type="text" name="name" class="profile-input {{ $errors->has('name') ? 'profile-input--error' : '' }}" value="{{ old('name') }}" required maxlength="255" autocomplete="off">
                        @error('name')
                            <p class="profile-error">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="profile-actions">
                        <button type="submit" class="btn-primary">Create group</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Your groups</h2>
            </div>
            <div class="evt-section-body evt-table-wrap">
                @if ($groups->isEmpty())
                    <p class="evt-muted">No groups yet. Create one above or import guests with a group column.</p>
                @else
                    <table class="evt-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Guests</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($groups as $group)
                                <tr>
                                    <td>
                                        <form method="post" action="{{ route('events.guest-groups.update', ['event' => $event, 'guest_group' => $group->id]) }}" class="evt-guest-group-rename">
                                            @csrf
                                            @method('PATCH')
                                            <input type="text" name="name" class="profile-input evt-guest-group-rename-input" value="{{ $group->name }}" required maxlength="255" aria-label="Group name">
                                            <button type="submit" class="evt-btn-outline evt-btn-tiny">Save</button>
                                        </form>
                                    </td>
                                    <td>{{ $group->guests_count }}</td>
                                    <td class="evt-table-actions">
                                        <form method="post" action="{{ route('events.guest-groups.destroy', ['event' => $event, 'guest_group' => $group->id]) }}" class="evt-inline-form evt-confirm-form" data-evt-confirm="Remove this group? Guests stay on the list; only the label is removed.">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="evt-btn-danger-outline evt-btn-tiny">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                                <tr class="evt-group-link-row">
                                    <td colspan="3">
                                        @if ($group->hasSeatPool())
                                            @php
                                                $taken = $group->seatsTaken();
                                                $pending = $group->seatsPending();
                                            @endphp
                                            <div class="evt-group-link">
                                                <p class="evt-group-link-count">
                                                    <strong>{{ $taken }} of {{ $group->seat_limit }} seats taken</strong>
                                                    @if ($pending > 0)
                                                        &middot; {{ $pending }} awaiting your approval
                                                    @endif
                                                    @if ($group->rsvp_link_closed_at)
                                                        &middot; <em>link closed</em>
                                                    @endif
                                                </p>
                                                <div class="evt-copy-row">
                                                    <input type="text" readonly class="profile-input" value="{{ $group->rsvpUrl() }}" aria-label="Group RSVP link" onclick="this.select()">
                                                    <button type="button" class="evt-btn-outline evt-btn-tiny" data-copy-text="{{ $group->rsvpUrl() }}">Copy link</button>
                                                </div>
                                                <div class="evt-group-link-actions">
                                                    <form method="post" action="{{ route('events.guest-groups.link.update', ['event' => $event, 'guest_group' => $group->id]) }}" class="evt-inline-form">
                                                        @csrf
                                                        @method('PUT')
                                                        <label class="evt-sr-only" for="seat_limit_{{ $group->id }}">Seats</label>
                                                        <input id="seat_limit_{{ $group->id }}" type="number" name="seat_limit" min="1" max="10000" class="profile-input" style="width:90px" value="{{ $group->seat_limit }}" required>
                                                        <button type="submit" class="evt-btn-outline evt-btn-tiny">Update seats</button>
                                                    </form>
                                                    <form method="post" action="{{ route('events.guest-groups.link.toggle', ['event' => $event, 'guest_group' => $group->id]) }}" class="evt-inline-form">
                                                        @csrf
                                                        @method('PATCH')
                                                        <button type="submit" class="evt-btn-outline evt-btn-tiny">{{ $group->rsvp_link_closed_at ? 'Reopen link' : 'Close link' }}</button>
                                                    </form>
                                                    <form method="post" action="{{ route('events.guest-groups.link.destroy', ['event' => $event, 'guest_group' => $group->id]) }}" class="evt-inline-form evt-confirm-form" data-evt-confirm="Turn off this link? The address stops working; members and their RSVPs stay.">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit" class="evt-btn-danger-outline evt-btn-tiny">Turn off link</button>
                                                    </form>
                                                </div>
                                            </div>
                                        @else
                                            <form method="post" action="{{ route('events.guest-groups.link.update', ['event' => $event, 'guest_group' => $group->id]) }}" class="evt-inline-form">
                                                @csrf
                                                @method('PUT')
                                                <span class="evt-muted">Share one link for this group, with a limited number of seats. You approve each request.</span>
                                                <label class="evt-sr-only" for="seat_limit_{{ $group->id }}">Seats</label>
                                                <input id="seat_limit_{{ $group->id }}" type="number" name="seat_limit" min="1" max="10000" class="profile-input" style="width:90px" placeholder="Seats" required>
                                                <button type="submit" class="evt-btn-outline evt-btn-tiny">Create group link</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>

    @push('scripts')
        <script src="{{ asset('js/guests-admin.js') }}" defer></script>
    @endpush
</x-app-layout>
