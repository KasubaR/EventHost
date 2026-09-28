@php
    [$nameBefore, $nameAfter] = \App\Support\WeddingInvitationView::for($event, $invitation)->names();
    $signature = $nameAfter !== '' ? $nameBefore.' & '.$nameAfter : $nameBefore;

    $wording = trim((string) $event->description);
    if ($wording === '') {
        $wording = 'Together with their families, the couple joyfully invite you to celebrate their wedding. Join us for a ceremony, dinner and dancing, and help us begin our next chapter with the people we love most.';
    }
@endphp

<section class="mg-section mg-section--alt" id="invitation">
    <div class="mg-wrap mg-wrap--text">
        <h2 class="mg-title">You're Invited</h2>
        <p class="mg-invite-text">{!! nl2br(e($wording)) !!}</p>
        @if ($event->rsvp_deadline)
            <p class="mg-invite-note">Please reply by {{ $event->rsvp_deadline->format('F jS') }}.</p>
        @endif
        <p class="mg-signature">{{ $signature }}</p>
    </div>
</section>
