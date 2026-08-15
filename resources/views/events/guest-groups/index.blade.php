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
