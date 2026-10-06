@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    $timeLine = $w->timeLine();
    $venueLine = $w->venueLine();
    $locationLine = $w->locationLine();
@endphp

<section class="wi2-details-section wi2-reveal" data-wi2-reveal>
    <div class="wi2-section-header">
        <span class="wi2-section-kicker">The Details</span>
        <h2 class="wi2-section-heading">When &amp; <em>Where</em></h2>
    </div>
    <div class="wi2-details-grid">
        <div class="wi2-detail-card">
            <p class="wi2-detail-label">When</p>
            <p class="wi2-detail-value">{{ $w->dateLine() }}</p>
            @if ($timeLine !== null)
                <p class="wi2-detail-sub">{{ $timeLine }}</p>
            @endif
        </div>
        @if ($venueLine !== '')
            <div class="wi2-detail-card">
                <p class="wi2-detail-label">Venue</p>
                <p class="wi2-detail-value">{{ $venueLine }}</p>
                @include('events.invitations.partials.map-link', ['class' => 'wi2-detail-link', 'searchOnly' => true])
            </div>
        @elseif (\App\Support\EventPlace::isUnknown($event))
            <div class="wi2-detail-card">
                <p class="wi2-detail-label">Venue</p>
                <p class="wi2-detail-value">{{ \App\Support\EventPlace::TO_BE_ANNOUNCED }}</p>
            </div>
        @endif
        @if ($locationLine !== '' || ($event->latitude !== null && $event->longitude !== null))
            <div class="wi2-detail-card">
                <p class="wi2-detail-label">Location</p>
                @if ($locationLine !== '')
                    <p class="wi2-detail-value">{{ $locationLine }}</p>
                @endif
                @include('events.invitations.partials.map-link', ['class' => 'wi2-detail-link'])
            </div>
        @endif
    </div>
</section>
