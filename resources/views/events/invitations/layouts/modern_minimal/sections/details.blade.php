@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    $timeLine = $w->timeLine();
    $venueLine = $w->venueLine();
    $locationLine = $w->locationLine();
@endphp

<section class="mm-section mm-details-section">
    <h2 class="mm-section-title">Details</h2>
    <div class="mm-details-row">
        <div class="mm-detail-block">
            <h3>When</h3>
            <p>{{ $w->dateLine() }}@if ($timeLine !== null)<br>{{ $timeLine }}@endif</p>
        </div>
        @if ($venueLine !== '')
            <div class="mm-detail-block">
                <h3>Venue</h3>
                <p>{{ $venueLine }}</p>
                @include('events.invitations.partials.map-link', ['class' => 'mm-detail-link', 'searchOnly' => true])
            </div>
        @elseif (\App\Support\EventPlace::isUnknown($event))
            <div class="mm-detail-block">
                <h3>Venue</h3>
                <p>{{ \App\Support\EventPlace::TO_BE_ANNOUNCED }}</p>
            </div>
        @endif
        @if ($locationLine !== '' || ($event->latitude !== null && $event->longitude !== null))
            <div class="mm-detail-block">
                <h3>Location</h3>
                @if ($locationLine !== '')
                    <p>{{ $locationLine }}</p>
                @endif
                @include('events.invitations.partials.map-link', ['class' => 'mm-detail-link'])
            </div>
        @endif
    </div>
</section>
