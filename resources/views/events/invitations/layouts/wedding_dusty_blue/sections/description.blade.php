@php
    [$nameBefore, $nameAfter] = \App\Support\WeddingInvitationView::for($event, $invitation)->names();
    $signature = $nameAfter !== '' ? $nameBefore.' & '.$nameAfter : $nameBefore;

    $wording = \App\Support\InvitationDescriptionFallback::for($event, 'We are getting married, and we would love for you to be there. Please save the date and join us as we celebrate the beginning of our forever.');
@endphp

<section class="db-section" id="invitation">
    <div class="db-wrap db-wrap--text">
        <h2 class="db-title">You're Invited</h2>
        @include('events.invitations.layouts.wedding_dusty_blue.partials.divider')
        <p class="db-invite-text">{!! nl2br(e($wording)) !!}</p>
        <p class="db-signature db-script">{{ $signature }}</p>
    </div>
</section>
