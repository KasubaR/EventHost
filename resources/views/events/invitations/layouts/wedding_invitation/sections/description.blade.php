@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);

    $intro = \App\Support\InvitationDescriptionFallback::for($event, 'We joyfully invite you to celebrate the union of two souls as they begin their forever journey together in love and laughter.');
    if (trim((string) $event->description) !== '') {
        $lines = preg_split('/\R/u', $intro, 2);
        $intro = trim((string) ($lines[0] ?? ''));
        if (strlen($intro) > 500) {
            $intro = \Illuminate\Support\Str::limit($intro, 500, '…');
        }
    }

    $couplePhotos = $w->couplePhotos();

    $caption = trim((string) ($invitation['content']['wi_couple_caption'] ?? ''));
    if ($caption === '') {
        $caption = 'Two hearts, one story';
    }
@endphp

<section class="wi-save-date wi-reveal" id="save-the-date" data-wi-reveal>
    <p class="wi-section-tag">You are cordially invited</p>
    <h2 class="wi-section-title">Save the <em>Date</em></h2>
    <div class="wi-orn" aria-hidden="true">· ◆ ·</div>
    <p class="wi-section-body">{{ $intro }}</p>
    <div class="wi-date-badge">
        <span class="wi-date-badge-weekday">{{ $event->event_date->format('l') }}</span>
        <span class="wi-date-badge-day">{{ $event->event_date->format('j') }}</span>
        <span class="wi-date-badge-month">{{ $event->event_date->format('F Y') }}</span>
    </div>
</section>

<section class="wi-couple-section wi-reveal" data-wi-reveal>
    <div class="wi-couple-grid">
        @foreach (['wi-couple-img-1', 'wi-couple-img-2', 'wi-couple-img-3'] as $idx => $class)
            <div class="wi-couple-img-wrap {{ $class }}">
                <img src="{{ $couplePhotos[$idx] }}" alt="{{ \App\Support\InvitationMediaUrl::photoAlt($event->name) }}" loading="lazy" width="600" height="420">
            </div>
        @endforeach
    </div>
    <p class="wi-couple-caption">{{ $caption }}</p>
</section>
