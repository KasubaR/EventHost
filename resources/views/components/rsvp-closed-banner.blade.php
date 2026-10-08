@props(['event'])

{{-- Host-side notice that guests can no longer respond, shown on the guest list and the event page so a
     host does not keep inviting people to a closed form. Invitation events only, and only once published.
     The "Extend the deadline" link appears only when there is a deadline to extend and the host did not close RSVPs by hand;
     a manual close has its own "Reopen RSVPs" button, and while RSVP is open "Close RSVPs" stops new answers (guests who
     already answered can still cancel or reduce). plans/rsvp-deadline-fixes.md G10, plans/rsvp-deadline-moments.md Phase 2. --}}
@php
    $isLive = $event->isInvitation() && $event->is_published;
    $closedReason = $isLive ? $event->rsvpClosedReason() : null;
    $canClose = $isLive && \App\Http\Controllers\EventRsvpClosureController::canClose($event) && $event->isRsvpOpen() && ! $event->isInvitationPaused();
@endphp

@if ($isLive && session('rsvp_reopened'))
    <div class="evt-flash evt-flash--info" role="status">
        <i class="fa-solid fa-lock-open"></i>
        RSVPs are open again. Guests are not told automatically; remind the ones who have not answered from the
        <a href="{{ session('rsvp_reopened') }}">guest list</a>.
    </div>
@endif

@if ($closedReason)
    <div class="evt-flash evt-flash--warn" role="status">
        <i class="fa-solid fa-lock"></i>
        <strong>RSVP is closed.</strong> {{ $closedReason }}
        @if ($event->rsvpManuallyClosed())
            New answers and extra guests are blocked; people who already answered can still cancel or take fewer seats until the event starts. Invitations and reminders are paused.
            @if (! $event->isLocked() && ! $event->isCancelled() && ! $event->trashed())
                <form method="POST" action="{{ route('events.rsvp-closure.destroy', $event) }}" style="display:inline">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="evt-btn-outline" style="margin-left:8px">Reopen RSVPs</button>
                </form>
            @endif
        @else
            Guests can no longer respond, and invitations and reminders are paused.
            @if ($event->rsvp_deadline !== null && ! $event->isLocked() && ! $event->isCancelled())
                <a href="{{ route('events.edit', $event) }}">Extend the deadline</a>
            @endif
        @endif
    </div>
@elseif ($canClose)
    <form method="POST" action="{{ route('events.rsvp-closure.store', $event) }}" class="evt-rsvp-close-form" style="margin-bottom:16px"
          onsubmit="return confirm('Stop taking RSVPs? Guests will not be able to answer or add people, but anyone who already answered can still cancel. You can reopen at any time.');">
        @csrf
        <button type="submit" class="evt-btn-outline"><i class="fa-solid fa-lock"></i> Close RSVPs</button>
    </form>
@endif
