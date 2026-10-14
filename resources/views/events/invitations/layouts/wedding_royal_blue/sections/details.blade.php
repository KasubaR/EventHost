@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    $timeLine = $w->timeLine();
    $venueLine = $w->venueLine();
    $locationLine = $w->locationLine();
    $hasMap = $event->latitude !== null && $event->longitude !== null;
    $dressCode = trim((string) ($invitation['content']['wi_couple_caption'] ?? ''));
@endphp

<section class="rb-section" id="details">
    <div class="rb-wrap">
        <h2 class="rb-title">Wedding Details</h2>
        @include('events.invitations.layouts.wedding_royal_blue.partials.ornament')
        <div class="rb-cards">
            <div class="rb-card">
                <span class="rb-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M8 3v4M16 3v4M3.5 10h17"/></svg></span>
                <h3 class="rb-card-title">When</h3>
                <p>{{ $w->dateLine() }}@if ($timeLine !== null)<br>{{ $timeLine }}@endif</p>
            </div>
            @if ($venueLine !== '')
                <div class="rb-card">
                    <span class="rb-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/></svg></span>
                    <h3 class="rb-card-title">Venue</h3>
                    <p>{{ $venueLine }}</p>
                    @include('events.invitations.partials.map-link', ['class' => 'rb-map-link', 'searchOnly' => true])
                </div>
            @elseif (\App\Support\EventPlace::isUnknown($event))
                <div class="rb-card">
                    <span class="rb-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/></svg></span>
                    <h3 class="rb-card-title">Venue</h3>
                    <p>{{ \App\Support\EventPlace::TO_BE_ANNOUNCED }}</p>
                </div>
            @endif
            @if ($locationLine !== '' || $hasMap)
                <div class="rb-card">
                    <span class="rb-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.6 2.6 2.6 14.4 0 17M12 3.5c-2.6 2.6-2.6 14.4 0 17"/></svg></span>
                    <h3 class="rb-card-title">Location</h3>
                    @if ($locationLine !== '')
                        <p>{{ $locationLine }}</p>
                    @endif
                    @include('events.invitations.partials.map-link', ['class' => 'rb-map-link'])
                </div>
            @endif
            @if ($dressCode !== '')
                <div class="rb-card">
                    <span class="rb-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 5.5a2 2 0 1 1 2 2l-2 1.5v1.5M12 10.5 3.5 17h17L12 10.5z"/></svg></span>
                    <h3 class="rb-card-title">Dress Code</h3>
                    <p>{{ $dressCode }}</p>
                </div>
            @endif
        </div>
    </div>
</section>
