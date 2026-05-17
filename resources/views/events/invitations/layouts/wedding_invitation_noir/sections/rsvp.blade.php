@php
    $nameTrim = trim((string) $event->name);
    $footerNames = preg_replace('/\s*&\s*/', ' ✦ ', $nameTrim) ?? $nameTrim;

    $monogram = trim((string) ($invitation['content']['wi2_footer_monogram'] ?? ''));
    if ($monogram === '') {
        $parts = preg_split('/\s*(?:&|and)\s*/i', $nameTrim, -1, PREG_SPLIT_NO_EMPTY);
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr(trim($part), 0, 1));
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
    if ($event->rsvp_deadline) {
        $deadlineText = 'Respond by '.$event->rsvp_deadline->format('jS F Y');
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
            @include('events.invitations.sections.rsvp')
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
