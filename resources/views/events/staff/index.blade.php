<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
    @endpush

    <x-slot name="title">Staff — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Staff</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Event</a>
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'staff-invited')
        <div class="evt-admin-flash">Invite sent.</div>
    @elseif (session('status') === 'staff-role-updated')
        <div class="evt-admin-flash">Role updated.</div>
    @elseif (session('status') === 'staff-invite-resent')
        <div class="evt-admin-flash">Invite resent.</div>
    @elseif (session('status') === 'staff-removed')
        <div class="evt-admin-flash">Staff access removed.</div>
    @endif

    <div class="evt-stack">
        @if (! $event->ownerHasPremiumEventTools())
            {{-- Ticketed events unlock staff on approval, not subscription
                 tier — EventHost already earns a commission on every ticket
                 sold, once sales are live. --}}
            <div class="evt-flash evt-flash--warn">
                <i class="fa-solid fa-triangle-exclamation"></i>
                Staff accounts unlock once EventHost approves ticket sales for this event.
                <a href="{{ route('events.ticket-types.index', $event) }}">Check your submission status</a>.
            </div>
        @else
            <div class="evt-section">
                <div class="evt-section-head">
                    <h2>Invite staff</h2>
                    <p>Event Manager gets full ticketing access — ticket types, orders, and check-in — short of activating sales, deleting the event, or managing staff. Check-in Staff can only scan at the door.</p>
                </div>
                <div class="evt-section-body profile-card-like">
                    <form method="post" action="{{ route('events.staff.store', $event) }}" class="profile-form-stack evt-staff-invite-form">
                        @csrf
                        <div class="evt-grid-2">
                            <div class="profile-field">
                                <label for="staff_name" class="profile-label">Name</label>
                                <input id="staff_name" type="text" name="name" class="profile-input {{ $errors->has('name') ? 'profile-input--error' : '' }}" value="{{ old('name') }}" required maxlength="255" autocomplete="off">
                                @error('name')
                                    <p class="profile-error">{{ $message }}</p>
                                @enderror
                            </div>
                            <div class="profile-field">
                                <label for="staff_email" class="profile-label">Email</label>
                                <input id="staff_email" type="email" name="email" class="profile-input {{ $errors->has('email') ? 'profile-input--error' : '' }}" value="{{ old('email') }}" required maxlength="255" autocomplete="off">
                                @error('email')
                                    <p class="profile-error">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        <div class="profile-field">
                            <label for="staff_role" class="profile-label">Role</label>
                            <select id="staff_role" name="role" data-cs data-cs-icon="fa-solid fa-user-shield" class="profile-input {{ $errors->has('role') ? 'profile-input--error' : '' }}" required>
                                @foreach (\App\Enums\EventStaffRole::cases() as $role)
                                    <option value="{{ $role->value }}" data-hint="{{ $role->description() }}" {{ old('role') === $role->value ? 'selected' : '' }}>{{ $role->label() }}</option>
                                @endforeach
                            </select>
                            @error('role')
                                <p class="profile-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="profile-actions">
                            <button type="submit" class="btn-primary">Send invite</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        <div class="evt-section">
            <div class="evt-section-head">
                <h2>Current staff</h2>
            </div>
            <div class="evt-section-body evt-table-wrap">
                @if ($staff->isEmpty())
                    <p class="evt-muted">No staff yet. Invite an Event Manager or Check-in Staff above.</p>
                @else
                    <table class="evt-table">
                        <thead>
                            <tr>
                                <th>Name / Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($staff as $member)
                                <tr>
                                    <td>
                                        <strong>{{ $member->displayName() }}</strong>
                                        <br><span class="evt-muted">{{ $member->email }}</span>
                                    </td>
                                    <td>
                                        <form method="post" action="{{ route('events.staff.update', ['event' => $event, 'eventStaff' => $member]) }}" class="evt-staff-role-form">
                                            @csrf
                                            @method('PATCH')
                                            <select name="role" data-cs data-cs-size="sm" class="profile-input" aria-label="Role">
                                                @foreach (\App\Enums\EventStaffRole::cases() as $role)
                                                    <option value="{{ $role->value }}" {{ $member->role === $role ? 'selected' : '' }}>{{ $role->label() }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="evt-btn-outline evt-btn-tiny">Save</button>
                                        </form>
                                    </td>
                                    <td>
                                        @if ($member->isPending())
                                            @if ($member->isExpired())
                                                <span class="evt-pill evt-pill--declined">Expired</span>
                                            @else
                                                <span class="evt-pill evt-pill--pending">Invited</span>
                                            @endif
                                        @else
                                            <span class="evt-pill evt-pill--accepted">Active</span>
                                        @endif
                                    </td>
                                    <td class="evt-table-actions">
                                        @if ($member->isPending())
                                            <form method="post" action="{{ route('events.staff.resend', ['event' => $event, 'eventStaff' => $member]) }}" class="evt-inline-form">
                                                @csrf
                                                <button type="submit" class="evt-btn-outline evt-btn-tiny">Resend</button>
                                            </form>
                                        @endif
                                        <form method="post" action="{{ route('events.staff.destroy', ['event' => $event, 'eventStaff' => $member]) }}" class="evt-inline-form evt-confirm-form" data-evt-confirm="Remove this person's access to this event?">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="evt-btn-danger-outline evt-btn-tiny">Remove</button>
                                        </form>
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
        <script src="{{ asset('js/tables-admin.js') }}" defer></script>
    @endpush
</x-app-layout>
