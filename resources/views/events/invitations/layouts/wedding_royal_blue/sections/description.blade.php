@php
    [$nameBefore, $nameAfter] = \App\Support\WeddingInvitationView::for($event, $invitation)->names();
    $signature = $nameAfter !== '' ? $nameBefore.' & '.$nameAfter : $nameBefore;

    $wording = \App\Support\InvitationDescriptionFallback::for($event, 'Together with their families, the couple joyfully invite you to celebrate their wedding. Join us for the ceremony and the celebration after it, and help us begin our next chapter with the people we love most.');
@endphp

<section class="rb-section" id="invitation">
    <div class="rb-wrap rb-wrap--text">
        <p class="rb-script rb-script--accent">You're invited</p>
        <p class="rb-invite-text">{!! nl2br(e($wording)) !!}</p>
        @if ($event->rsvpDeadlineAt())
            <p class="rb-invite-note">Please reply by {{ $event->rsvpDeadlineAt()->format('F jS'.' \a\t g:i A T') }}.</p>
        @endif
        <p class="rb-signature">{{ $signature }}</p>
    </div>
</section>
