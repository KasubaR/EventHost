<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/events-admin.css') }}">
        <link rel="stylesheet" href="{{ asset('css/custom-select.css') }}">
    @endpush

    @php $isRegistrations = $event->isFreeRegistration(); @endphp

    <x-slot name="title">{{ $isRegistrations ? 'Registrations' : 'Guests' }} | {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">{{ $isRegistrations ? 'Registrations' : 'Guests' }}</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                @php $exportParams = array_merge(['event' => $event], array_filter(['response' => request('response'), 'q' => request('q'), 'group' => request('group'), 'invitation_sent' => request('invitation_sent'), 'plus_one' => request('plus_one'), 'checked_in' => request('checked_in')], fn($v) => $v !== null && $v !== '')); @endphp
                <a href="{{ route('events.guests.export', $exportParams) }}" class="evt-btn-outline"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
                <a href="{{ route('events.guests.export-pdf', $exportParams) }}" class="evt-btn-outline"><i class="fa-solid fa-file-pdf"></i> Export PDF</a>
                <a href="{{ route('events.guests.import.create', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-file-import"></i> Import</a>
                {{-- Shown either way: silently dropping it left hosts hunting for a
                     feature they simply had not paid for. Matches the "QR check-in &
                     photo wall" link on the event page. --}}
                @php $hasPremiumTools = $event->ownerHasPremiumEventTools(); @endphp
                <a href="{{ $hasPremiumTools ? route('events.guests.qr-sheet', $event) : \App\Support\BillingPlan::checkoutUrlForTier(\App\Enums\SubscriptionTier::Pro) }}" class="evt-btn-outline">
                    <i class="fa-solid fa-qrcode"></i> Print QR badges
                    @unless ($hasPremiumTools)
                        <span class="evt-credit-badge">Pro</span>
                    @endunless
                </a>
                @php $guestCapacityReached = $event->hasReachedGuestCapacity(); @endphp
                <a href="{{ $guestCapacityReached ? \App\Support\BillingPlan::checkoutUrlForTier($event->nextGuestCapacityTier()) : route('events.guests.create', $event) }}" class="btn-primary">
                    <i class="fa-solid fa-user-plus"></i> {{ $isRegistrations ? 'Add registration' : 'Add guest' }}
                    @if ($guestCapacityReached)
                        <span class="evt-credit-badge">{{ $event->nextGuestCapacityTier()->label() }}</span>
                    @endif
                </a>
                <a href="{{ route('events.guest-groups.index', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-layer-group"></i> Groups</a>
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Event</a>
            </div>
        </div>
    </x-slot>

    <x-rsvp-closed-banner :event="$event" />

    @foreach (['action', 'whatsapp_share'] as $errorKey)
        @if ($errors->has($errorKey))
            <div class="evt-admin-flash evt-admin-flash--error" role="alert">{{ $errors->first($errorKey) }}</div>
        @endif
    @endforeach

    @if (is_array($waAgain = session('whatsapp_already_sent')))
        @php
            $waAgainAt = filled($waAgain['sent_at'] ?? null)
                ? \Illuminate\Support\Carbon::parse($waAgain['sent_at'])->timezone(config('events.timezone'))
                : null;
        @endphp
        <div class="evt-admin-flash">
            @if ($waAgain['in_flight'] ?? false)
                The WhatsApp invitation to <bdi>{{ $waAgain['name'] }}</bdi> is still sending. Nothing more was sent.
            @else
                <bdi>{{ $waAgain['name'] }}</bdi> was already sent a WhatsApp invitation{{ $waAgainAt ? ' on '.$waAgainAt->format('M j \a\t H:i') : '' }}. Nothing more was sent, and nothing was charged.
                <form method="post" action="{{ route('events.guests.whatsapp-invite', ['event' => $event, 'guest' => $waAgain['guest_id']]) }}" class="evt-inline-form">
                    @csrf
                    <input type="hidden" name="resend" value="1">
                    <button type="submit" class="evt-btn-outline evt-btn-tiny">Send again</button>
                </form>
            @endif
        </div>
    @endif

    @if ($latestImport && ! $latestImport->isFinished())
        <div class="evt-admin-flash">
            @if ($latestImport->isStale())
                The import of <bdi>{{ $latestImport->original_name }}</bdi> stopped responding.
            @else
                Importing guests from <bdi>{{ $latestImport->original_name }}</bdi>: {{ number_format($latestImport->processed_rows) }} of {{ number_format($latestImport->total_rows) }} rows checked so far.
            @endif
            <a href="{{ route('events.guests.import.show', ['event' => $event, 'guestImport' => $latestImport]) }}">View the import</a>
        </div>
    @elseif ($unseenImport)
        <div class="evt-admin-flash">
            @if ($unseenImport->status === \App\Models\GuestImport::STATUS_FAILED)
                {{ $unseenImport->failure_message }}
            @else
                Import of <bdi>{{ $unseenImport->original_name }}</bdi> finished.
            @endif
            @include('events.guests.partials.import-summary', ['summary' => $unseenImport->summary()])
        </div>
    @endif

    @if (session('email_typo'))
        @php $typo = session('email_typo'); @endphp
        <div class="evt-admin-flash">
            Saved <bdi>{{ $typo['guest'] }}</bdi> with <bdi>{{ $typo['email'] }}</bdi>. Did you mean <bdi>{{ $typo['suggestion'] }}</bdi>?
            <a href="{{ $typo['url'] }}">Use that address</a>. Emails to a wrong address never arrive.
        </div>
    @endif
    @if (session('guest_contact_reset'))
        <div class="evt-admin-flash">Marked not sent: <bdi>{{ session('guest_contact_reset') }}</bdi>'s new email or phone has not received an invitation yet. Send it again, or tick "Keep it marked as sent" when you edit a guest you have already told.</div>
    @endif
    @if (session('old_link_still_works'))
        @php $olw = session('old_link_still_works'); @endphp
        <div class="evt-admin-flash">
            <bdi>{{ $olw['guest'] }}</bdi>'s old email address can still open their link and pass. If it was wrong or belongs to someone else, replace the link.
            Any pass PDF or image already sent, and any printed QR, stops working.
            <form method="POST" action="{{ $olw['url'] }}" style="display:inline" onsubmit="return confirm('Replace this guest\'s link? The old link and any pass already sent stop working.');">
                @csrf
                <button type="submit" class="evt-btn-outline evt-btn-tiny">Replace link now</button>
            </form>
        </div>
    @endif
    @if (session('guest_link_replaced'))
        @php $glr = session('guest_link_replaced'); @endphp
        <div class="evt-admin-flash">New link for <bdi>{{ $glr['guest'] }}</bdi>. Send it to them; the old one no longer works: <span class="evt-word-break" dir="ltr">{{ $glr['link'] }}</span></div>
    @endif
    @if (session('status') === 'guest-created')
        <div class="evt-admin-flash">{{ $isRegistrations ? 'Registration added.' : 'Guest added.' }}</div>
        @if (session('guest_unreachable'))
            <div class="evt-admin-flash"><bdi>{{ session('guest_unreachable') }}</bdi> has no email or phone, so we can't send them anything. Copy their link from the row menu and share it yourself, or add a contact detail.</div>
        @endif
        @if (session('guest_shared_phone'))
            <div class="evt-admin-flash">This number is shared with <bdi>{{ session('guest_shared_phone') }}</bdi>. WhatsApp messages for either of them go to the same phone.</div>
        @endif
        @if (session('guest_same_name'))
            <div class="evt-admin-flash">Another guest on this list is also called <bdi>{{ session('guest_same_name') }}</bdi>. If they're different people, an email or phone helps you tell them apart. If not, remove the extra one.</div>
        @endif
    @elseif (session('status') === 'guest-updated')
        <div class="evt-admin-flash">{{ $isRegistrations ? 'Registration updated.' : 'Guest updated.' }}</div>
        @if (session('plus_one_kept'))
            <div class="evt-admin-flash">{{ session('plus_one_kept') }} has already confirmed a plus-one, so it stays. Open their edit page to remove it.</div>
        @endif
        @if (session('plus_one_inactive'))
            <div class="evt-admin-flash"><bdi>{{ session('plus_one_inactive') }}</bdi> is allowed a plus-one, but plus-ones are switched off for this event, so it has no effect yet. Turn them on in the event's details.</div>
        @endif
        @if (session('plus_one_offered'))
            @php $offer = session('plus_one_offered'); @endphp
            <div class="evt-admin-flash">
                <bdi>{{ $offer['guest'] }}</bdi> has said yes for one seat and can now bring a plus-one, but they will only see it if they reopen their invitation.
                @if ($offer['has_email'])
                    <form method="POST" action="{{ $offer['url'] }}" style="display:inline">
                        @csrf
                        <button type="submit" class="evt-btn-outline evt-btn-tiny">Tell them</button>
                    </form>
                @else
                    They have no email address, so let them know yourself.
                @endif
            </div>
        @endif
    @elseif (session('status') === 'plus-one-offer-sent')
        <div class="evt-admin-flash">Told <bdi>{{ session('plus_one_told') }}</bdi> they can bring a plus-one.</div>
    @elseif (session('status') === 'guest-deleted')
        <div class="evt-admin-flash">{{ $isRegistrations ? 'Registration removed.' : 'Guest removed.' }}</div>
        @if (session('guest_removed'))
            @php $gr = session('guest_removed'); @endphp
            @if ($gr['seats_freed'] > 0)
                <div class="evt-admin-flash"><bdi>{{ $gr['name'] }}</bdi> held {{ $gr['seats_freed'] }} {{ Str::plural('seat', $gr['seats_freed']) }}, now free again.
                    @if ($gr['pool'])
                        <bdi>{{ $gr['pool']['group'] }}</bdi> has {{ $gr['pool']['left'] }} {{ Str::plural('seat', $gr['pool']['left']) }} left.
                    @endif
                </div>
            @endif
            @if ($gr['tell_url'])
                <div class="evt-admin-flash">
                    <bdi>{{ $gr['name'] }}</bdi> still has their link and may not know they were removed.
                    <form method="POST" action="{{ $gr['tell_url'] }}" style="display:inline">
                        @csrf
                        <button type="submit" class="evt-btn-outline evt-btn-tiny">Tell them</button>
                    </form>
                </div>
            @endif
        @endif
    @elseif (session('status') === 'removed-guest-told')
        <div class="evt-admin-flash">Told <bdi>{{ session('removed_told') }}</bdi> they were removed.</div>
    @elseif (session('status') === 'email-retry')
        <div class="evt-admin-flash">Email to <bdi>{{ session('email_retry') }}</bdi> will be sent again. If it bounces again, it will show here.</div>
    @elseif (session('status') === 'guest-invitation-marked-sent')
        <div class="evt-admin-flash">Invitation marked as sent.</div>
    @elseif (session('status') === 'guest-whatsapp-sent')
        <div class="evt-admin-flash">WhatsApp invitation sent.</div>
    @elseif (session('status') === 'guest-whatsapp-invalid-phone')
        <div class="evt-admin-flash evt-admin-flash--error">Couldn't send. Automated WhatsApp needs a Zambian mobile number. For any other number, use Share on WhatsApp.</div>
    @elseif (session('status') === 'guest-whatsapp-rate-limited')
        <div class="evt-admin-flash">This event has hit its hourly WhatsApp sending limit. Try again shortly.</div>
    @elseif (session('status') === 'guest-whatsapp-disabled')
        <div class="evt-admin-flash">WhatsApp sending isn't set up for this account yet.</div>
    @elseif (session('status') === 'guest-whatsapp-unpublished')
        <div class="evt-admin-flash">Publish this event before sending invitations. Until then, guest links show "Invitation unavailable".</div>
    @elseif (session('status') === 'guest-whatsapp-closed')
        <div class="evt-admin-flash">RSVP is closed, so no invitation was sent. Extend the RSVP deadline first.</div>
    @elseif (session('status') === 'guest-whatsapp-failed')
        <div class="evt-admin-flash">Couldn't send the WhatsApp invitation. Try again in a moment.</div>
    @elseif (session('status') === 'guests-imported')
        <div class="evt-admin-flash">
            Import finished.
            @include('events.guests.partials.import-summary', ['summary' => [
                'created' => (int) session('import_created', 0),
                'skipped' => (int) session('import_skipped', 0),
                'capped' => (int) session('import_capped', 0),
                'invalid' => (int) session('import_invalid', 0),
                'problems' => session('import_problems', []),
                'skipped_rows' => session('import_skipped_rows', []),
            ]])
        </div>
    @elseif (session('status') === 'guests-bulk-group')
        <div class="evt-admin-flash">
            {{ session('bulk_count', 0) }} {{ Str::plural('guest', session('bulk_count', 0)) }} updated.
            @if (session('bulk_skipped', 0) > 0)
                {{ session('bulk_skipped') }} not moved: {{ session('bulk_skip_reason') }}
            @endif
        </div>
    @elseif (session('status') === 'guests-bulk-table')
        <div class="evt-admin-flash">Selected guests updated.</div>
    @elseif (session('status') === 'guests-bulk-sent')
        <div class="evt-admin-flash">Invitation marked sent for selected guests.</div>
    @elseif (session('status') === 'guests-bulk-deleted')
        <div class="evt-admin-flash">Selected guests removed.</div>
        @if (session('bulk_removed') && session('bulk_removed')['seats_freed'] > 0)
            <div class="evt-admin-flash">{{ session('bulk_removed')['seats_freed'] }} {{ Str::plural('seat', session('bulk_removed')['seats_freed']) }} freed.</div>
        @endif
    @elseif (session('status') === 'guests-bulk-reminder')
        <div class="evt-admin-flash">
            Reminder emails queued for {{ session('bulk_count', 0) }} guest(s).
            @if (session('bulk_skipped', 0) > 0)
                {{ session('bulk_skipped') }} skipped: {{ session('bulk_skip_reason') }}.
            @endif
        </div>
    @elseif (session('status') === 'guests-bulk-update')
        <div class="evt-admin-flash">
            Update emails queued for {{ session('bulk_count', 0) }} guest(s).
            @if (session('bulk_skipped', 0) > 0)
                {{ session('bulk_skipped') }} skipped: {{ session('bulk_skip_reason') }}.
            @endif
        </div>
    @elseif (session('status') === 'guests-bulk-plus-one')
        <div class="evt-admin-flash">{{ session('bulk_count', 0) }} {{ Str::plural('guest', session('bulk_count', 0)) }} can now bring a plus-one.</div>
    @endif

    <div class="evt-stack">
        <div class="evt-grid-2 evt-rsvp-summary-grid">
            <div class="evt-stat-card">
                <div class="evt-stat-value">
                    {{ $stats['total'] }}@if ($event->guestCapacity() !== null)<span class="evt-stat-value-of"> / {{ $event->guestCapacity() }}</span>@endif
                </div>
                <div class="evt-stat-label">{{ $isRegistrations ? 'Total registrations' : 'Total guests' }}</div>
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
            @if ($event->hasRsvpApprovalQueue())
                <div class="evt-stat-card">
                    <div class="evt-stat-value">{{ $stats['awaiting_approval'] }}</div>
                    <div class="evt-stat-label">Awaiting your approval</div>
                </div>
            @endif
        </div>

        <button type="button" class="evt-btn-outline evt-guest-tools-toggle" data-guest-tools-open aria-controls="guest-tools-drawer" aria-expanded="false">Filters &amp; bulk actions</button>
        <div class="evt-guest-tools-backdrop" data-guest-tools-close hidden></div>

        <div class="evt-guest-tools-wrap">
        <div class="evt-filter-row">
            @php
                $filters = [
                    'all'       => 'All',
                    'pending'   => 'Pending',
                    'responded' => 'Responded',
                    'accepted'  => 'Accepted',
                    'declined'  => 'Declined',
                    'maybe'     => 'Maybe',
                ];
                if ($event->hasRsvpApprovalQueue()) {
                    $filters['awaiting_approval'] = 'Awaiting approval';
                }
                $filterParams = array_filter([
                    'q' => request('q'),
                    'group' => request('group'),
                    'invitation_sent' => request('invitation_sent'),
                    'plus_one' => request('plus_one'),
                    'checked_in' => request('checked_in'),
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

        <div id="guest-tools-drawer" class="evt-guest-tools" role="dialog" aria-label="Filters and bulk actions">
        <div class="evt-guest-tools-head">
            <strong>Filters &amp; bulk actions</strong>
            <button type="button" class="evt-guest-tools-x" data-guest-tools-close aria-label="Close">&times;</button>
        </div>
        <div class="evt-section evt-guest-toolbar-section">
            <div class="evt-section-body evt-guest-toolbar">
                <form method="get" action="{{ route('events.guests.index', $event) }}" class="evt-guest-filter-form">
                    <label class="evt-sr-only" for="guest_search_q">Search guests</label>
                    <input id="guest_search_q" type="search" name="q" class="profile-input evt-guest-search-input" value="{{ request('q') }}" placeholder="Search name, email, phone" maxlength="255" autocomplete="off">

                    <label class="evt-sr-only" for="guest_filter_group">Group</label>
                    <select id="guest_filter_group" name="group" class="profile-input evt-guest-filter-select" data-cs data-cs-search="never" aria-label="Filter by group">
                        <option value="all" @selected(request('group', 'all') === 'all')>All groups</option>
                        <option value="none" @selected(request('group') === 'none')>No group</option>
                        @foreach ($groups as $g)
                            <option value="{{ $g->id }}" @selected((string) request('group') === (string) $g->id)>{{ $g->name }}</option>
                        @endforeach
                    </select>

                    <label class="evt-sr-only" for="guest_filter_sent">Invitation sent</label>
                    <select id="guest_filter_sent" name="invitation_sent" class="profile-input evt-guest-filter-select" data-cs data-cs-search="never" aria-label="Invitation sent">
                        <option value="" @selected(request('invitation_sent') === null || request('invitation_sent') === '')>Any invitation status</option>
                        <option value="yes" @selected(request('invitation_sent') === 'yes')>Invitation sent</option>
                        <option value="no" @selected(request('invitation_sent') === 'no')>Not marked sent</option>
                    </select>

                    <label class="evt-sr-only" for="guest_filter_plus_one">Plus-one</label>
                    <select id="guest_filter_plus_one" name="plus_one" class="profile-input evt-guest-filter-select" data-cs data-cs-search="never" aria-label="Plus-one allowed">
                        <option value="" @selected(request('plus_one') === null || request('plus_one') === '')>Any plus-one</option>
                        <option value="yes" @selected(request('plus_one') === 'yes')>Plus-one allowed</option>
                        <option value="no" @selected(request('plus_one') === 'no')>Plus-one not allowed</option>
                    </select>

                    <label class="evt-sr-only" for="guest_filter_checked_in">Checked in</label>
                    <select id="guest_filter_checked_in" name="checked_in" class="profile-input evt-guest-filter-select" data-cs data-cs-search="never" aria-label="Checked in">
                        <option value="" @selected(request('checked_in') === null || request('checked_in') === '')>Any check-in status</option>
                        <option value="yes" @selected(request('checked_in') === 'yes')>Checked in</option>
                        <option value="no" @selected(request('checked_in') === 'no')>Not checked in</option>
                    </select>

                    @if (request()->filled('response'))
                        <input type="hidden" name="response" value="{{ request('response') }}">
                    @endif

                    <button type="submit" class="evt-btn-outline evt-btn-tiny">Apply</button>
                </form>
            </div>
        </div>

        <form id="guest-bulk-form" method="post" action="{{ route('events.guests.bulk', $event) }}" class="evt-guest-bulk-bar evt-confirm-form" data-evt-confirm="Apply this action to all selected guests?">
            @csrf
            @foreach (['q', 'response', 'group', 'invitation_sent', 'plus_one', 'checked_in'] as $paramKey)
                @php $pv = request($paramKey); @endphp
                @if ($pv !== null && $pv !== '')
                    <input type="hidden" name="{{ $paramKey }}" value="{{ $pv }}">
                @endif
            @endforeach

            @php $canSendReminders = $event->ownerCanSendAutomatedReminders(); @endphp
            <label class="evt-sr-only" for="bulk_action_select">Bulk action</label>
            <select id="bulk_action_select" name="action" class="profile-input evt-guest-filter-select" data-cs data-cs-search="never" required aria-label="Bulk action">
                <option value="" disabled selected>Bulk action…</option>
                <option value="assign_group">Assign group</option>
                <option value="assign_table">Assign table</option>
                <option value="mark_sent">Mark invitation sent</option>
                @if ($event->allow_plus_one)
                    <option value="allow_plus_one">Allow plus-one</option>
                @endif
                <option value="send_reminder_email">Send reminder email{{ $canSendReminders ? '' : ' (Pro+)' }}</option>
                <option value="send_update_email">Send event update email</option>
                <option value="prepare_whatsapp_share">Share on WhatsApp (one chat at a time)</option>
                <option value="delete">Remove guests</option>
            </select>

            <label class="evt-sr-only" for="bulk_group_select">Group for bulk assign</label>
            <select id="bulk_group_select" name="guest_group_id" class="profile-input evt-guest-filter-select" data-cs data-cs-search="never" aria-label="Assign to group">
                <option value="">Clear group</option>
                @foreach ($groups as $g)
                    <option value="{{ $g->id }}">{{ $g->name }}</option>
                @endforeach
            </select>

            <label class="evt-sr-only" for="bulk_table_select">Table for bulk assign</label>
            <select id="bulk_table_select" name="event_table_id" class="profile-input evt-guest-filter-select" data-cs data-cs-search="never" aria-label="Assign to table">
                <option value="">Clear table</option>
                @foreach ($tables as $t)
                    <option value="{{ $t->id }}">{{ $t->label }}</option>
                @endforeach
            </select>

            <label class="evt-sr-only" for="bulk_days_until">Reminder timing</label>
            <select id="bulk_days_until" name="days_until" class="profile-input evt-guest-filter-select" data-cs data-cs-search="never" aria-label="Reminder timing">
                <option value="7">7 days reminder</option>
                <option value="3" selected>3 days reminder</option>
                <option value="1">1 day reminder</option>
            </select>

            <label class="evt-sr-only" for="bulk_update_message">Update message</label>
            <input id="bulk_update_message" type="text" name="update_message" maxlength="1000" class="profile-input evt-guest-search-input" placeholder="Event update message (required for update action)">

            <button type="submit" class="evt-btn-outline">Apply to selected</button>
        </form>
        </div>
        </div>

        <div class="evt-section">
            <div class="evt-section-body evt-table-wrap">
                @if ($guests->isEmpty())
                    <p class="evt-muted">{{ $isRegistrations ? 'No registrations match this filter.' : 'No guests match this filter.' }}</p>
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
                                <th>Table</th>
                                <th>Response</th>
                                <th>Attendees</th>
                                <th>{{ $isRegistrations ? 'Status' : 'Invitation' }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($guests as $guestRow)
                                @php
                                    $rsvpRow = $guestRow->rsvp;
                                    $rsvpUrl = $guestRow->personalRsvpUrl();
                                    $shareProblem = $shareBlockedReason ?? $whatsAppShare->guestProblem($guestRow);
                                    $shareMessage = $whatsAppShare->message($event, $guestRow);
                                    $sharedAt = $lastShared[$guestRow->id] ?? null;
                                @endphp
                                <tr>
                                    <td>
                                        <input type="checkbox" name="guest_ids[]" value="{{ $guestRow->id }}" form="guest-bulk-form" data-evt-guest-select aria-label="Select {{ $guestRow->name }}">
                                    </td>
                                    <td class="evt-guest-name-cell" dir="auto">{{ $guestRow->name }}@if (\App\Support\PrintableText::mayNotPrint($guestRow->name))
                                        <span class="evt-muted" dir="ltr" title="The pass PDF, pass image and printed list cannot draw every character of this name.">(may not print correctly)</span>
                                    @endif</td>
                                    <td class="evt-guest-contact-cell">
                                        @if ($guestRow->email)
                                            <span class="evt-guest-contact-line">{{ $guestRow->email }}</span>
                                            @if ($emailProblem = $emailProblems->for($guestRow))
                                                @if ($emailProblem['kind'] === 'bounced')
                                                    <span class="evt-guest-email-failed">Email bounced: {{ $emailProblem['reason'] }}. Check the address.</span>
                                                    @can('pause', $event)
                                                        <form method="POST" action="{{ route('events.guests.email-retry', [$event, $guestRow]) }}" class="evt-guest-email-retry">
                                                            @csrf
                                                            <button type="submit" class="evt-btn-outline evt-btn-tiny">Try this address again</button>
                                                        </form>
                                                    @endcan
                                                @else
                                                    <span class="evt-guest-email-failed">Email not delivered: {{ $emailProblem['reason'] }}</span>
                                                @endif
                                            @endif
                                        @endif
                                        @if ($guestRow->phone)
                                            <span class="evt-guest-contact-line evt-guest-phone" dir="ltr">{{ \App\Support\GuestPhone::display($guestRow->phone) }}@if (\App\Support\GuestPhone::isZambianLandline($guestRow->phone))
                                                <span class="evt-muted">(landline: no WhatsApp)</span>
                                            @endif</span>
                                        @endif
                                        @if ($guestRow->hasNoContactDetails())
                                            <span class="evt-muted">No contact details. Share their link yourself.</span>
                                        @endif
                                    </td>
                                    <td>{{ $guestRow->group?->name ?? '-' }}</td>
                                    <td>{{ $guestRow->tableLabel() ?? '-' }}</td>
                                    <td>
                                        @if ($rsvpRow)
                                            <span class="evt-pill evt-pill--{{ $rsvpRow->status->value }}">{{ ucfirst($rsvpRow->status->value) }}</span>
                                            @php
                                                // Only meaningful for an Accepted RSVP on an event that holds
                                                // RSVPs for review — NotRequired never renders a second pill.
                                                $approvalPillClass = match ($rsvpRow->host_approval_status) {
                                                    \App\Enums\RsvpApprovalStatus::Pending => 'pending',
                                                    \App\Enums\RsvpApprovalStatus::Approved => 'accepted',
                                                    \App\Enums\RsvpApprovalStatus::Rejected => 'declined',
                                                    \App\Enums\RsvpApprovalStatus::NotRequired => null,
                                                };
                                            @endphp
                                            @if ($approvalPillClass)
                                                <span class="evt-pill evt-pill--{{ $approvalPillClass }}">{{ $rsvpRow->host_approval_status->label() }}</span>
                                            @endif
                                            @if ($guestRow->previously_removed_at)
                                                <span class="evt-pill evt-pill--pending" title="The host removed this person earlier and they signed up again.">Previously removed</span>
                                            @endif
                                        @else
                                            <span class="evt-pill evt-pill--pending">Pending</span>
                                        @endif
                                    </td>
                                    <td>{{ $rsvpRow && $rsvpRow->status->countsTowardGuestLimit() ? $rsvpRow->attendee_count : '-' }}</td>
                                    <td>
                                        @if ($guestRow->invitation_token === null)
                                            {{-- No personal invite link was ever issued — this row came
                                                 in through open registration, not a host invite, so
                                                 "Not marked" would misread as a pending task. --}}
                                            <span class="evt-pill evt-pill--accepted">Registered</span>
                                        @elseif ($guestRow->invitation_sent)
                                            <span class="evt-pill evt-pill--accepted">Sent</span>
                                            @if ($guestRow->invitation_sent_at)
                                                <span class="evt-muted evt-guest-sent-meta">{{ $guestRow->invitation_sent_at->timezone(config('app.timezone'))->format('M j, Y') }}</span>
                                            @endif
                                        @else
                                            <span class="evt-pill evt-pill--pending">Not marked</span>
                                        @endif
                                        @if ($undeliveredLog = $undeliveredWhatsApp[$guestRow->id] ?? null)
                                            <span class="evt-guest-wa-failed">WhatsApp not delivered: {{ $undeliveredLog->undeliveredReason() }}</span>
                                        @endif
                                    </td>
                                    <td class="evt-table-actions">
                                        <div class="evt-more" data-evt-more>
                                            <button type="button" class="evt-icon-btn evt-more-toggle" data-evt-more-toggle hidden aria-expanded="false" aria-haspopup="true" aria-label="More actions for {{ $guestRow->name }}">
                                                <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                                            </button>
                                            <div class="evt-more-menu" data-evt-more-menu>
                                                @if ($rsvpUrl)
                                                    <button type="button" class="evt-more-item" role="menuitem" data-copy-text="{{ $rsvpUrl }}" data-copy-label="Copy link">
                                                        <i class="fa-solid fa-link" aria-hidden="true"></i>
                                                        <span data-copy-label-text>Copy link</span>
                                                    </button>
                                                    @if ($shareMessage)
                                                        <button type="button" class="evt-more-item" role="menuitem" data-copy-text="{{ $shareMessage }}" data-copy-label="Copy invitation message">
                                                            <i class="fa-regular fa-copy" aria-hidden="true"></i>
                                                            <span data-copy-label-text>Copy invitation message</span>
                                                        </button>
                                                    @endif
                                                    {{-- The free wa.me link, opened on the host's own phone. It goes through our route so opening it marks
                                                         the invitation sent; the paid Twilio send is the separate item below. --}}
                                                    @if ($shareProblem === null)
                                                        <a href="{{ route('events.guests.whatsapp-share', ['event' => $event, 'guest' => $guestRow->id]) }}" class="evt-more-item" role="menuitem" target="_blank" rel="noopener noreferrer">
                                                            <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                                                            <span>{{ $sharedAt ? 'Share again on WhatsApp' : 'Share on WhatsApp' }}</span>
                                                            @if ($sharedAt)
                                                                <span class="evt-more-item-meta">opened {{ $sharedAt->timezone(config('app.timezone'))->format('M j') }}</span>
                                                            @endif
                                                        </a>
                                                    @else
                                                        <span class="evt-more-item evt-more-item--disabled" role="menuitem" aria-disabled="true" title="{{ $shareProblem }}">
                                                            <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                                                            <span>Share on WhatsApp</span>
                                                        </span>
                                                    @endif
                                                    @if ($event->ownerHasPremiumEventTools())
                                                        <a href="{{ route('events.guests.qr', ['event' => $event, 'guest' => $guestRow->id]) }}" class="evt-more-item" role="menuitem" data-evt-qr-open data-qr-name="{{ $guestRow->name }}" data-qr-filename="{{ $guestRow->qrDownloadName() }}">
                                                            <i class="fa-solid fa-qrcode" aria-hidden="true"></i>
                                                            <span>QR check-in</span>
                                                        </a>
                                                    @endif
                                                    {{-- Server-initiated Twilio send, distinct from the wa.me manual link above. Pro and
                                                         above only (real per-message cost) — see plans/whatsapp-invitations.md. Hidden
                                                         entirely (not shown as a locked upsell) while Twilio isn't configured at all, since
                                                         even a Pro host couldn't use it yet. --}}
                                                    @if ($whatsappSendEnabled)
                                                        @if ($event->ownerHasPremiumEventTools())
                                                            @if (\App\Support\ZambianPhone::isValid($guestRow->phone))
                                                                <form method="post" action="{{ route('events.guests.whatsapp-invite', ['event' => $event, 'guest' => $guestRow->id]) }}" class="evt-inline-form">
                                                                    @csrf
                                                                    <button type="submit" class="evt-more-item" role="menuitem">
                                                                        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                                                                        <span>Send WhatsApp Invitation</span>
                                                                    </button>
                                                                </form>
                                                            @else
                                                                <span class="evt-more-item evt-more-item--disabled" role="menuitem" aria-disabled="true" title="{{ \App\Support\ZambianPhone::isInternationalForShare($guestRow->phone) ? 'Automated WhatsApp is for Zambian mobile numbers. Use Share on WhatsApp instead.' : 'Add a valid Zambian mobile number to send a WhatsApp invitation' }}">
                                                                    <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                                                                    <span>Send WhatsApp Invitation</span>
                                                                </span>
                                                            @endif
                                                        @else
                                                            <a href="{{ \App\Support\BillingPlan::checkoutUrlForTier(\App\Enums\SubscriptionTier::Pro) }}" class="evt-more-item" role="menuitem">
                                                                <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                                                                <span>Send WhatsApp Invitation</span>
                                                                <span class="evt-credit-badge">Pro</span>
                                                            </a>
                                                        @endif
                                                    @endif
                                                @endif
                                                @if (!$guestRow->invitation_sent && $guestRow->invitation_token)
                                                    <form method="post" action="{{ route('events.guests.mark-invitation-sent', ['event' => $event, 'guest' => $guestRow->id]) }}" class="evt-inline-form">
                                                        @csrf
                                                        @method('PATCH')
                                                        <button type="submit" class="evt-more-item" role="menuitem">
                                                            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                                                            <span>Mark sent</span>
                                                        </button>
                                                    </form>
                                                @endif
                                                @if ($rsvpRow?->host_approval_status === \App\Enums\RsvpApprovalStatus::Pending)
                                                    <form method="post" action="{{ route('events.guests.rsvp.approve', ['event' => $event, 'guest' => $guestRow->id]) }}" class="evt-inline-form">
                                                        @csrf
                                                        @method('PATCH')
                                                        <button type="submit" class="evt-more-item" role="menuitem">
                                                            <i class="fa-solid fa-check" aria-hidden="true"></i>
                                                            <span>Approve RSVP</span>
                                                        </button>
                                                    </form>
                                                    <button type="button" class="evt-more-item evt-more-item--danger" role="menuitem"
                                                            data-evt-rsvp-reject-open
                                                            data-reject-action="{{ route('events.guests.rsvp.reject', ['event' => $event, 'guest' => $guestRow->id]) }}"
                                                            data-reject-name="{{ $guestRow->name }}">
                                                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                                                        <span>Decline RSVP</span>
                                                    </button>
                                                @endif
                                                <a href="{{ route('events.guests.edit', ['event' => $event, 'guest' => $guestRow->id]) }}" class="evt-more-item" role="menuitem">
                                                    <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                                    <span>Edit</span>
                                                </a>
                                                <form method="post" action="{{ route('events.guests.destroy', ['event' => $event, 'guest' => $guestRow->id]) }}" class="evt-inline-form evt-confirm-form" data-evt-confirm="{{ $guestRow->removalWarning() }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="evt-more-item evt-more-item--danger" role="menuitem">
                                                        <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                                        <span>Remove</span>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
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

    @if ($removedGuests->isNotEmpty())
        <details class="evt-removed-guests">
            <summary>Removed guests ({{ $removedGuests->count() }})</summary>
            <ul>
                @foreach ($removedGuests as $removal)
                    <li>
                        <bdi>{{ $removal->name }}</bdi>
                        <span class="evt-removed-meta">{{ $removal->summary() }}, removed {{ $removal->removed_at->diffForHumans() }}</span>
                        @if ($removal->notified_at)
                            <span class="evt-removed-meta">Told</span>
                        @elseif ($removal->canBeTold())
                            <form method="POST" action="{{ route('events.guests.removed.notify', ['event' => $event, 'removal' => $removal]) }}" style="display:inline">
                                @csrf
                                <button type="submit" class="evt-btn-outline evt-btn-tiny">Tell them</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="evt-removed-meta">Removed guests are listed for your reference. Their links no longer work.</p>
        </details>
    @endif

    <div class="evt-qr-lightbox" data-evt-qr-lightbox hidden>
        <div class="evt-qr-lightbox-backdrop" data-evt-qr-close tabindex="-1"></div>
        <div class="evt-qr-lightbox-dialog" role="dialog" aria-modal="true" aria-labelledby="evt-qr-lightbox-title">
            <button type="button" class="evt-qr-lightbox-close" data-evt-qr-close aria-label="Close">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
            <h2 id="evt-qr-lightbox-title" class="evt-qr-lightbox-title" data-evt-qr-title>Check-in QR</h2>
            <p class="evt-qr-lightbox-sub">Staff scan this at the door to check the guest in.</p>
            <div class="evt-qr-lightbox-frame">
                <img data-evt-qr-img alt="" width="240" height="240">
            </div>
            <div class="evt-qr-lightbox-actions">
                <a href="#" class="evt-btn-outline" data-evt-qr-download download>
                    <i class="fa-solid fa-download" aria-hidden="true"></i>
                    Download
                </a>
                <button type="button" class="btn-primary" data-evt-qr-share hidden>
                    <i class="fa-solid fa-share-nodes" aria-hidden="true"></i>
                    Share
                </button>
            </div>
        </div>
    </div>

    {{-- Single shared modal, rewired per row by data-evt-rsvp-reject-open triggers above —
         same pattern as the QR lightbox, since a modal per table row would mean duplicate ids.
         plans/rsvp-host-approval.md. --}}
    <div class="profile-modal-overlay" data-evt-rsvp-reject-modal>
        <div class="profile-modal">
            <div class="profile-modal-header">
                <div class="profile-modal-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <h3 data-evt-rsvp-reject-title>Decline this RSVP?</h3>
                <p>The guest will be told why and will not receive a confirmation or entry pass for this response.</p>
            </div>

            <form method="post" class="profile-modal-form" data-evt-rsvp-reject-form>
                @csrf
                @method('PATCH')

                <div class="profile-field">
                    <label for="rsvp_reject_note" class="profile-label">Note to the guest</label>
                    <textarea id="rsvp_reject_note" name="host_rejection_note" class="profile-input" rows="3" maxlength="2000" required placeholder="Let them know why, e.g. the event has reached capacity."></textarea>
                </div>

                <div class="profile-modal-actions">
                    <button type="button" class="profile-modal-cancel" data-evt-rsvp-reject-close>Cancel</button>
                    <button type="submit" class="danger-confirm-btn">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i> Decline RSVP
                    </button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
        <script src="{{ asset('js/custom-select.js') }}" defer></script>
        <script src="{{ asset('js/guests-admin.js') }}" defer></script>
    @endpush
</x-app-layout>
