@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);

    $wording = trim((string) $event->description);
    if ($wording === '') {
        $wording = 'We joyfully invite you to celebrate the union of two souls as they begin their forever journey together in love and laughter.';
    }

    $caption = trim((string) ($invitation['content']['wi_couple_caption'] ?? ''));
@endphp

<section class="mm-section mm-invitation" id="invitation">
    <h2 class="mm-section-title">You're Invited</h2>
    <p class="mm-invitation-text">{!! nl2br(e($wording)) !!}</p>
    <div class="mm-couple-grid">
        @foreach ($w->couplePhotos() as $src)
            <div class="mm-couple-item">
                <img src="{{ $src }}" alt="{{ \App\Support\InvitationMediaUrl::photoAlt($event->name) }}" loading="lazy" width="600" height="800">
            </div>
        @endforeach
    </div>
    @if ($caption !== '')
        <p class="mm-couple-caption">{{ $caption }}</p>
    @endif
</section>
