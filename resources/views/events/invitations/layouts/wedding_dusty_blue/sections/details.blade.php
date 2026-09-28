@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    $timeLine = $w->timeLine();
    $venueLine = $w->venueLine();
    $locationLine = $w->locationLine();
    $hasMap = $event->latitude !== null && $event->longitude !== null;
    $dressCode = trim((string) ($invitation['content']['wi_couple_caption'] ?? ''));
@endphp

<section class="db-section" id="details">
    <div class="db-wrap">
        <h2 class="db-title">Details</h2>
        @include('events.invitations.layouts.wedding_dusty_blue.partials.divider')
        <div class="db-panel db-cards">
            <div class="db-card">
                <h3 class="db-card-title">When</h3>
                <p>{{ $w->dateLine() }}@if ($timeLine !== null)<br>{{ $timeLine }}@endif</p>
            </div>
            @if ($venueLine !== '')
                <div class="db-card">
                    <h3 class="db-card-title">Venue</h3>
                    <p>{{ $venueLine }}</p>
                </div>
            @endif
            @if ($locationLine !== '' || $hasMap)
                <div class="db-card">
                    <h3 class="db-card-title">Location</h3>
                    @if ($locationLine !== '')
                        <p>{{ $locationLine }}</p>
                    @endif
                    @include('events.invitations.partials.map-link', ['class' => 'db-map-link'])
                </div>
            @endif
            @if ($dressCode !== '')
                <div class="db-card">
                    <h3 class="db-card-title">Dress Code</h3>
                    <p class="db-italic">{{ $dressCode }}</p>
                </div>
            @endif
        </div>
    </div>
</section>
