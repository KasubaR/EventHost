@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    $coverUrl = $event->cover_image_url;
    $timeLine = $w->timeLine();
    $venueLine = $w->venueLine();
    $locationLine = $w->locationLine();
@endphp

<section
    class="wi-details-section wi-reveal"
    data-wi-reveal
    @if ($coverUrl) style="--wi-details-bg: url('{{ $coverUrl }}');" @endif
>
    <div class="wi-details-inner">
        <p class="wi-section-tag">Event Details</p>
        <h2 class="wi-section-title">The <em>Celebration</em></h2>
        <div class="wi-orn" aria-hidden="true">· ◆ ·</div>
        <div class="wi-details-grid">
            <div class="wi-detail-card">
                <div class="wi-detail-icon" aria-hidden="true"><i class="fa-regular fa-calendar"></i></div>
                <p class="wi-detail-label">When</p>
                <p class="wi-detail-value">{{ $w->dateLine() }}</p>
                @if ($timeLine !== null)
                    <p class="wi-detail-sub">{{ $timeLine }}</p>
                @endif
            </div>
            @if ($venueLine !== '')
                <div class="wi-detail-card">
                    <div class="wi-detail-icon" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></div>
                    <p class="wi-detail-label">Venue</p>
                    <p class="wi-detail-value">{{ $venueLine }}</p>
                    @include('events.invitations.partials.map-link', ['class' => 'wi-detail-link', 'searchOnly' => true])
                </div>
            @elseif (\App\Support\EventPlace::isUnknown($event))
                <div class="wi-detail-card">
                    <div class="wi-detail-icon" aria-hidden="true"><i class="fa-solid fa-location-dot"></i></div>
                    <p class="wi-detail-label">Venue</p>
                    <p class="wi-detail-value">{{ \App\Support\EventPlace::TO_BE_ANNOUNCED }}</p>
                </div>
            @endif
            @if ($locationLine !== '' || ($event->latitude !== null && $event->longitude !== null))
                <div class="wi-detail-card">
                    <div class="wi-detail-icon" aria-hidden="true"><i class="fa-regular fa-map"></i></div>
                    <p class="wi-detail-label">Location</p>
                    @if ($locationLine !== '')
                        <p class="wi-detail-value">{{ $locationLine }}</p>
                    @endif
                    @include('events.invitations.partials.map-link', ['class' => 'wi-detail-link'])
                </div>
            @endif
        </div>
    </div>
</section>
