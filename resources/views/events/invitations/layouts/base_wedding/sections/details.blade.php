@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    $timeLine = $w->timeLine();
    $venueLine = $w->venueLine();
    $locationLine = $w->locationLine();
@endphp

<section class="bw-section bw-details">
    <p class="bw-tag">Event Details</p>
    <h2 class="bw-title">The <em>Celebration</em></h2>
    <div class="bw-details-grid">
        <div class="bw-detail">
            <i class="fa-regular fa-calendar bw-detail-icon" aria-hidden="true"></i>
            <p class="bw-detail-label">When</p>
            <p class="bw-detail-value">{{ $w->dateLine() }}</p>
            @if ($timeLine !== null)
                <p class="bw-detail-sub">{{ $timeLine }}</p>
            @endif
        </div>
        @if ($venueLine !== '')
            <div class="bw-detail">
                <i class="fa-solid fa-location-dot bw-detail-icon" aria-hidden="true"></i>
                <p class="bw-detail-label">Venue</p>
                <p class="bw-detail-value">{{ $venueLine }}</p>
            </div>
        @endif
        @if ($locationLine !== '' || ($event->latitude !== null && $event->longitude !== null))
            <div class="bw-detail">
                <i class="fa-regular fa-map bw-detail-icon" aria-hidden="true"></i>
                <p class="bw-detail-label">Location</p>
                @if ($locationLine !== '')
                    <p class="bw-detail-value">{{ $locationLine }}</p>
                @endif
                @include('events.invitations.partials.map-link', ['class' => 'bw-detail-link'])
            </div>
        @endif
    </div>
</section>
