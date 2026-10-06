@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    $timeLine = $w->timeLine();
    $venueLine = $w->venueLine();
    $locationLine = $w->locationLine();
    $hasMap = $event->latitude !== null && $event->longitude !== null;
    $dressCode = trim((string) ($invitation['content']['wi_couple_caption'] ?? ''));
@endphp

<section class="mg-section mg-section--alt" id="details">
    <div class="mg-wrap">
        <h2 class="mg-title">Details</h2>
        <div class="mg-cards">
            <div class="mg-card">
                <h3 class="mg-card-title">When</h3>
                <p>{{ $w->dateLine() }}@if ($timeLine !== null)<br>{{ $timeLine }}@endif</p>
            </div>
            @if ($venueLine !== '')
                <div class="mg-card">
                    <h3 class="mg-card-title">Venue</h3>
                    <p>{{ $venueLine }}</p>
                    @include('events.invitations.partials.map-link', ['class' => 'mg-map-link', 'searchOnly' => true])
                </div>
            @elseif (\App\Support\EventPlace::isUnknown($event))
                <div class="mg-card">
                    <h3 class="mg-card-title">Venue</h3>
                    <p>{{ \App\Support\EventPlace::TO_BE_ANNOUNCED }}</p>
                </div>
            @endif
            @if ($locationLine !== '' || $hasMap)
                <div class="mg-card">
                    <h3 class="mg-card-title">Location</h3>
                    @if ($locationLine !== '')
                        <p>{{ $locationLine }}</p>
                    @endif
                    @include('events.invitations.partials.map-link', ['class' => 'mg-map-link'])
                </div>
            @endif
            @if ($dressCode !== '')
                <div class="mg-card">
                    <h3 class="mg-card-title">Dress Code</h3>
                    <p>{{ $dressCode }}</p>
                </div>
            @endif
        </div>
    </div>
</section>
