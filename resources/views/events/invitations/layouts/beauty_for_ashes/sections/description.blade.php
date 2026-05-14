@php
    $p1 = trim((string) ($invitation['content']['contact_phone_primary'] ?? ''));
    $p2 = trim((string) ($invitation['content']['contact_phone_secondary'] ?? ''));
    $lead = trim((string) $event->description);
    if ($lead !== '') {
        $parts = preg_split('/\R{2,}/u', $lead, 2);
        $lead = trim((string) ($parts[1] ?? $parts[0] ?? ''));
        $lead = \Illuminate\Support\Str::limit($lead, 320, '…');
    }
@endphp

<section class="bfa-section bfa-contact" id="contact">
    <div class="bfa-section-inner bfa-contact-inner">
        <div class="bfa-gold-rule" aria-hidden="true"></div>
        <div class="bfa-section-label bfa-section-label--center">Get in touch</div>
        <h2 class="bfa-section-heading bfa-section-heading--center">Contact us</h2>
        @if ($lead !== '')
            <p class="bfa-section-lead bfa-section-lead--center">{{ $lead }}</p>
        @else
            <p class="bfa-section-lead bfa-section-lead--center">For more information about this event, registration, or partnerships, reach out directly.</p>
        @endif

        @if ($p1 !== '' || $p2 !== '')
            <div class="bfa-contact-phones">
                @if ($p1 !== '')
                    <div class="bfa-contact-phone">
                        <span class="bfa-contact-label">Call / WhatsApp</span>
                        <span class="bfa-contact-value">{{ $p1 }}</span>
                    </div>
                @endif
                @if ($p2 !== '')
                    <div class="bfa-contact-phone">
                        <span class="bfa-contact-label">Call / WhatsApp</span>
                        <span class="bfa-contact-value">{{ $p2 }}</span>
                    </div>
                @endif
            </div>
        @endif

        <div class="bfa-contact-actions">
            <a href="#home" class="bfa-btn-primary">Back to top</a>
        </div>
    </div>
</section>
