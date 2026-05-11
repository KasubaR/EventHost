<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/forms-app.css') }}">
    @endpush

    <x-slot name="title">Guests — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Guests</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.guests.import.create', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-file-import"></i> Import</a>
                <a href="{{ route('events.guests.create', $event) }}" class="btn-primary"><i class="fa-solid fa-user-plus"></i> Add guest</a>
                <a href="{{ route('events.guest-groups.index', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-layer-group"></i> Groups</a>
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Event</a>
            </div>
        </div>
    </x-slot>

    @if (session('status') === 'guest-created')
        <div class="evt-admin-flash">Guest added.</div>
    @elseif (session('status') === 'guest-updated')
        <div class="evt-admin-flash">Guest updated.</div>
    @elseif (session('status') === 'guest-deleted')
        <div class="evt-admin-flash">Guest removed.</div>
    @elseif (session('status') === 'guest-invitation-marked-sent')
        <div class="evt-admin-flash">Invitation marked as sent.</div>
    @elseif (session('status') === 'guests-imported')
        <div class="evt-admin-flash">
            Import finished. Added {{ session('import_created', 0) }}, skipped {{ session('import_skipped', 0) }}.
        </div>
    @elseif (session('status') === 'guests-bulk-group')
        <div class="evt-admin-flash">Selected guests updated.</div>
    @elseif (session('status') === 'guests-bulk-sent')
        <div class="evt-admin-flash">Invitation marked sent for selected guests.</div>
    @elseif (session('status') === 'guests-bulk-deleted')
        <div class="evt-admin-flash">Selected guests removed.</div>
    @elseif (session('status') === 'guests-bulk-reminder')
        <div class="evt-admin-flash">Reminder emails queued for {{ session('bulk_count', 0) }} guest(s).</div>
    @elseif (session('status') === 'guests-bulk-update')
        <div class="evt-admin-flash">Update emails queued for {{ session('bulk_count', 0) }} guest(s).</div>
    @elseif (session('status') === 'guests-bulk-whatsapp')
        <div class="evt-admin-flash">Selected guests prepared for WhatsApp sharing.</div>
    @endif

    <div class="evt-stack">
        <div class="evt-grid-2 evt-rsvp-summary-grid">
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ $stats['total'] }}</div>
                <div class="evt-stat-label">Total guests</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ $stats['pending'] }}</div>
                <div class="evt-stat-label">Pending RSVP</div>
            </div>
            <div class="evt-stat-card evt-stat-card--accent">
                <div class="evt-stat-value">{{ $stats['accepted'] }}</div>
                <div class="evt-stat-label">Accepted</div>
            </div>
            <div class="evt-stat-card">
                <div class="evt-stat-value">{{ $stats['declined'] }}</div>
                <div class="evt-stat-label">Declined</div>
            </div>
        </div>

        <div class="evt-section evt-guest-toolbar-section">
            <div class="evt-section-body evt-guest-toolbar">
                <form method="get" action="{{ route('events.guests.index', $event) }}" class="evt-guest-filter-form">
                    <label class="evt-sr-only" for="guest_search_q">Search guests</label>
                    <input id="guest_search_q" type="search" name="q" class="profile-input evt-guest-search-input" value="{{ request('q') }}" placeholder="Search name, email, phone" maxlength="255" autocomplete="off">

                    <label class="evt-sr-only" for="guest_filter_group">Group</label>
                    <select id="guest_filter_group" name="group" class="profile-input evt-guest-filter-select" aria-label="Filter by group">
                        <option value="all" @selected(request('group', 'all') === 'all')>All groups</option>
                        <option value="none" @selected(request('group') === 'none')>No group</option>
                        @foreach ($groups as $g)
                            <option value="{{ $g->id }}" @selected((string) request('group') === (string) $g->id)>{{ $g->name }}</option>
                        @endforeach
                    </select>

                    <label class="evt-sr-only" for="guest_filter_sent">Invitation sent</label>
                    <select id="guest_filter_sent" name="invitation_sent" class="profile-input evt-guest-filter-select" aria-label="Invitation sent">
                        <option value="" @selected(request('invitation_sent') === null || request('invitation_sent') === '')>Any invitation status</option>
                        <option value="yes" @selected(request('invitation_sent') === 'yes')>Invitation sent</option>
                        <option value="no" @selected(request('invitation_sent') === 'no')>Not marked sent</option>
                    </select>

                    <label class="evt-sr-only" for="guest_filter_plus_one">Plus-one</label>
                    <select id="guest_filter_plus_one" name="plus_one" class="profile-input evt-guest-filter-select" aria-label="Plus-one allowed">
                        <option value="" @selected(request('plus_one') === null || request('plus_one') === '')>Any plus-one</option>
                        <option value="yes" @selected(request('plus_one') === 'yes')>Plus-one allowed</option>
                        <option value="no" @selected(request('plus_one') === 'no')>Plus-one not allowed</option>
                    </select>

                    @if (request()->filled('response'))
                        <input type="hidden" name="response" value="{{ request('response') }}">
                    @endif

                    <button type="submit" class="evt-btn-outline evt-btn-tiny">Apply</button>
                </form>
            </div>
        </div>

        <div class="evt-filter-row">
            @php
                $filters = [
                    'all' => 'All',
                    'pending' => 'Pending',
                    'accepted' => 'Accepted',
                    'declined' => 'Declined',
                    'maybe' => 'Maybe',
                ];
                $filterParams = array_filter([
                    'q' => request('q'),
                    'group' => request('group'),
                    'invitation_sent' => request('invitation_sent'),
                    'plus_one' => request('plus_one'),
                ], fn ($v) => $v !== null && $v !== '');
            @endphp
            @foreach ($filters as $key => $label)
                @php
                    $filterHrefParams = array_merge($filterParams, $key === 'all' ? [] : ['response' => $key]);
                    $filterHref = route('events.guests.index', array_merge(['event' => $event], $filterHrefParams));
                @endphp
                <a href="{{ $filterHref }}"
                   class="evt-filter-chip {{ $filter === $key ? 'evt-filter-chip--active' : '' }}">{{ $label }}</a>
            @endforeach
        </div>

        <form id="guest-bulk-form" method="post" action="{{ route('events.guests.bulk', $event) }}" class="evt-guest-bulk-bar evt-confirm-form" data-evt-confirm="Apply this action to all selected guests?">
            @csrf
            @foreach (['q', 'response', 'group', 'invitation_sent', 'plus_one'] as $paramKey)
                @php $pv = request($paramKey); @endphp
                @if ($pv !== null && $pv !== '')
                    <input type="hidden" name="{{ $paramKey }}" value="{{ $pv }}">
                @endif
            @endforeach

            <label class="evt-sr-only" for="bulk_action_select">Bulk action</label>
            <select id="bulk_action_select" name="action" class="profile-input evt-guest-filter-select" required aria-label="Bulk action">
                <option value="" disabled selected>Bulk action…</option>
                <option value="assign_group">Assign group</option>
                <option value="mark_sent">Mark invitation sent</option>
                <option value="send_reminder_email">Send reminder email</option>
                <option value="send_update_email">Send event update email</option>
                <option value="prepare_whatsapp_share">Prepare WhatsApp share</option>
                <option value="delete">Remove guests</option>
            </select>

            <label class="evt-sr-only" for="bulk_group_select">Group for bulk assign</label>
            <select id="bulk_group_select" name="guest_group_id" class="profile-input evt-guest-filter-select" aria-label="Assign to group">
                <option value="">Clear group</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                @endforeach
            </select>

            <label class="evt-sr-only" for="bulk_days_until">Reminder timing</label>
            <select id="bulk_days_until" name="days_until" class="profile-input evt-guest-filter-select" aria-label="Reminder timing">
                <option value="7">7 days reminder</option>
                <option value="3" selected>3 days reminder</option>
                <option value="1">1 day reminder</option>
            </select>

            <label class="evt-sr-only" for="bulk_update_message">Update message</label>
            <input id="bulk_update_message" type="text" name="update_message" maxlength="1000" class="profile-input evt-guest-search-input" placeholder="Event update message (required for update action)">

            <button type="submit" class="evt-btn-outline">Apply to selected</button>
        </form>

        <div class="evt-section">
            <div class="evt-section-body evt-table-wrap">
                @if ($guests->isEmpty())
                    <p class="evt-muted">No guests match this filter.</p>
                @else
                    <table class="evt-table evt-guest-table" data-evt-guest-bulk-table>
                        <thead>
                            <tr>
                                <th class="evt-table-checkbox-col" scope="col">
                                    <input type="checkbox" form="guest-bulk-form" data-evt-select-all-guests aria-label="Select all guests on this page">
                                </th>
                                <th>Name</th>
                                <th>Contact</th>
                                <th>Group</th>
                                <th>Response</th>
                                <th>Attendees</th>
                                <th>Invitation</th>
                                <th>Share</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($guests as $guestRow)
                                @php
                                    $rsvpRow = $guestRow->rsvp;
                                    $rsvpUrl = $guestRow->personalRsvpUrl();
                                    $waUrl = $rsvpUrl && $guestRow->phone
                                        ? \App\Support\WhatsAppInviteLink::url(
                                            $guestRow->phone,
                                            \App\Support\WhatsAppInviteLink::invitationMessage($guestRow->name, $event->name, $rsvpUrl)
                                        )
                                        : null;
                                @endphp
                                <tr>
                                    <td>
                                        <input type="checkbox" name="guest_ids[]" value="{{ $guestRow->id }}" form="guest-bulk-form" data-evt-guest-select aria-label="Select {{ $guestRow->name }}">
                                    </td>
                                    <td>{{ $guestRow->name }}</td>
                                    <td class="evt-guest-contact-cell">
                                        @if ($guestRow->email)
                                            <span class="evt-guest-contact-line">{{ $guestRow->email }}</span>
                                        @endif
                                        @if ($guestRow->phone)
                                            <span class="evt-guest-contact-line">{{ $guestRow->phone }}</span>
                                        @endif
                                        @if (!$guestRow->email && !$guestRow->phone)
                                            <span class="evt-muted">—</span>
                                        @endif
                                    </td>
                                    <td>{{ $guestRow->group?->name ?? '—' }}</td>
                                    <td>
                                        @if ($rsvpRow)
                                            <span class="evt-pill evt-pill--{{ $rsvpRow->status->value }}">{{ ucfirst($rsvpRow->status->value) }}</span>
                                        @else
                                            <span class="evt-pill evt-pill--pending">Pending</span>
                                        @endif
                                    </td>
                                    <td>{{ $rsvpRow && $rsvpRow->status->countsTowardGuestLimit() ? $rsvpRow->attendee_count : '—' }}</td>
                                    <td>
                                        @if ($guestRow->invitation_sent)
                                            <span class="evt-pill evt-pill--accepted">Sent</span>
                                            @if ($guestRow->invitation_sent_at)
                                                <span class="evt-muted evt-guest-sent-meta">{{ $guestRow->invitation_sent_at->timezone(config('app.timezone'))->format('M j, Y') }}</span>
                                            @endif
                                        @else
                                            <span class="evt-pill evt-pill--pending">Not marked</span>
                                        @endif
                                    </td>
                                    <td class="evt-guest-share-cell">
                                        @if ($rsvpUrl)
                                            <div class="evt-copy-row">
                                                <button type="button" class="evt-icon-btn" data-copy-text="{{ $rsvpUrl }}" data-copy-label="Copy link" title="Copy RSVP link">Copy link</button>
                                                @if ($waUrl)
                                                    <a href="{{ $waUrl }}" class="evt-icon-btn evt-icon-btn--wa" target="_blank" rel="noopener noreferrer" title="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                                                @else
                                                    <span class="evt-muted evt-icon-btn evt-icon-btn--disabled" title="Add a phone number for WhatsApp sharing"><i class="fa-brands fa-whatsapp"></i></span>
                                                @endif
                                            </div>
                                        @else
                                            <span class="evt-muted">Open RSVP</span>
                                        @endif
                                    </td>
                                    <td class="evt-table-actions">
                                        @if (!$guestRow->invitation_sent && $guestRow->invitation_token)
                                            <form method="post" action="{{ route('events.guests.mark-invitation-sent', ['event' => $event, 'guest' => $guestRow->id]) }}" class="evt-inline-form">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="evt-btn-outline evt-btn-tiny">Mark sent</button>
                                            </form>
                                        @endif
                                        <a href="{{ route('events.guests.edit', ['event' => $event, 'guest' => $guestRow->id]) }}" class="evt-btn-outline evt-btn-tiny">Edit</a>
                                        <form method="post" action="{{ route('events.guests.destroy', ['event' => $event, 'guest' => $guestRow->id]) }}" class="evt-inline-form evt-confirm-form" data-evt-confirm="Remove this guest? Their RSVP will be deleted too.">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="evt-btn-danger-outline evt-btn-tiny">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    {{ $guests->links() }}
                @endif
            </div>
        </div>
    </div>

    @push('scripts')
        <script src="{{ asset('js/guests-admin.js') }}" defer></script>
    @endpush
</x-app-layout>
