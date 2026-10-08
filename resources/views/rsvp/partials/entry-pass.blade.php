{{--
    Shown only when RsvpController::guestHasEntryPass() passed — see callers. $rsvp is
    optional (not every caller has it in scope, e.g. rsvp.closed) and only used for the
    awaiting-approval / declined messaging below — see plans/rsvp-host-approval.md.
--}}
@if ($showEntryPass ?? false)
    @php
        $passEvent = $event ?? $guest->event;
        $passCard = \App\Support\GuestPassCard::for($guest, $passEvent, theme: $invitation['theme'] ?? null);
    @endphp
    <div class="gpass-panel">
        <p class="gpass-panel-badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> You're going!</p>
        @if (($rsvp ?? null)?->isAwaitingHostApproval())
            <p class="gpass-panel-note">Your extra seat is waiting for the host. This pass is valid for {{ $rsvp->approvedSeatsOnFile() }} {{ $rsvp->approvedSeatsOnFile() === 1 ? 'seat' : 'seats' }} until they approve it.</p>
        @endif

        @include('rsvp.partials.pass-card', ['card' => $passCard, 'guest' => $guest])

        <div class="gpass-actions">
            <a href="{{ route('rsvp.token.pass', ['token' => $guest->invitation_token]) }}" class="rsvp-pass-download">
                <i class="fa-solid fa-id-card" aria-hidden="true"></i>
                Open full pass
            </a>
            <a href="{{ route('rsvp.token.pass-download', ['token' => $guest->invitation_token]) }}" class="rsvp-pass-download">
                <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
                Download PDF
            </a>
            <a href="{{ route('rsvp.token.pass-image', ['token' => $guest->invitation_token, 'download' => 1]) }}" class="rsvp-pass-download">
                <i class="fa-solid fa-image" aria-hidden="true"></i>
                Save as image
            </a>
        </div>
    </div>
@elseif (($rsvp ?? null)?->host_approval_status === \App\Enums\RsvpApprovalStatus::Pending)
    <div class="gpass-panel gpass-panel--pending">
        <p class="gpass-panel-badge gpass-panel-badge--pending"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i> Awaiting host confirmation</p>
        <p>The host reviews RSVPs before sending out passes. You'll get your confirmation and entry pass as soon as they approve yours.</p>
    </div>
@elseif (($rsvp ?? null)?->host_approval_status === \App\Enums\RsvpApprovalStatus::Rejected && $rsvp->status === \App\Enums\RsvpStatus::Accepted)
    <div class="gpass-panel gpass-panel--rejected">
        <p class="gpass-panel-badge gpass-panel-badge--rejected"><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> Not confirmed</p>
        <p>The host was not able to confirm your RSVP.</p>
        @if (filled($rsvp->host_rejection_note))
            <p class="gpass-panel-note">"{{ $rsvp->host_rejection_note }}"</p>
        @endif
    </div>
@endif
