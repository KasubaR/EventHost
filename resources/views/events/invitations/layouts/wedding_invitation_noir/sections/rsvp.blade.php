@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    [$nameBefore, $nameAfter] = $w->names();
    $footerNames = $nameAfter !== '' ? $nameBefore.' ✦ '.$nameAfter : $nameBefore;

    $monogram = trim((string) ($invitation['content']['wi2_footer_monogram'] ?? ''));
    if ($monogram === '') {
        $letters = '';
        foreach (array_filter([$nameBefore, $nameAfter]) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        $monogram = $letters !== '' ? $letters : '♥';
    }

    $footerLegal = trim((string) ($invitation['content']['wi2_footer_legal'] ?? ''));
    if ($footerLegal === '') {
        $footerLegal = 'With Love & Gratitude';
    }

    $locationShort = trim((string) $event->location_name);
    $footerDate = $event->event_date->format('j F Y');
    if ($locationShort !== '') {
        $footerDate .= ' · '.$locationShort;
    }

    $rsvpLead = 'Your presence would mean the world to us. Please respond at your earliest convenience so we may prepare every detail with care.';
    $deadlineText = '';
    if ($event->rsvpDeadlineAt()) {
        $deadlineText = 'Respond by '.$event->rsvpDeadlineAt()->format('jS F Y'.' \a\t g:i A T');
    }
@endphp

<section class="wi2-rsvp-section wi2-reveal" id="rsvp" data-wi2-reveal>
    <div class="wi2-rsvp-inner">
        <div class="wi2-rsvp-left">
            <span class="wi2-section-kicker">Kindly Reply</span>
            <h2 class="wi2-section-heading">Will you <em>join us?</em></h2>
            <p>{{ $rsvpLead }}</p>
            @if ($deadlineText !== '')
                <div class="wi2-rsvp-deadline">{{ $deadlineText }}</div>
            @endif
        </div>
        <div class="wi2-rsvp-form-col">
            @include('events.invitations.sections.rsvp', ['rsvpWrapperHasId' => true])
        </div>
    </div>
</section>

<footer class="wi2-footer wi2-reveal" data-wi2-reveal>
    <p class="wi2-footer-monogram">{{ $monogram }}</p>
    <p class="wi2-footer-names">{{ $footerNames }}</p>
    <p class="wi2-footer-date">{{ $footerDate }}</p>
    <hr class="wi2-footer-rule" aria-hidden="true">
    <p class="wi2-footer-legal">{{ $footerLegal }}</p>
</footer>
