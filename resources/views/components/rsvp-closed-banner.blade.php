@props(['event'])

{{-- Host-side notice that guests can no longer respond, shown on the guest list and the event page so a
     host does not keep inviting people to a closed form. Invitation events only, and only once published.
     The "Extend the deadline" link appears only when there is a deadline to extend. plans/rsvp-deadline-fixes.md G10. --}}
@php($closedReason = ($event->isInvitation() && $event->is_published) ? $event->rsvpClosedReason() : null)

@if ($closedReason)
    <div class="evt-flash evt-flash--warn" role="status">
        <i class="fa-solid fa-lock"></i>
        <strong>RSVP is closed.</strong> {{ $closedReason }}
        Guests can no longer respond, and invitations and reminders are paused.
        @if ($event->rsvp_deadline !== null && ! $event->isLocked() && ! $event->isCancelled())
            <a href="{{ route('events.edit', $event) }}">Extend the deadline</a>
        @endif
    </div>
@endif
