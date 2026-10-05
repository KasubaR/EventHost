@php
    $w = \App\Support\WeddingInvitationView::for($event, $invitation);
    [$nameBefore, $nameAfter] = $w->names();
    $venueLine = $w->venueLine();
    $locationLine = $w->locationLine();
@endphp

<section class="db-hero" id="top">
    <img class="db-hero-bg" src="{{ $event->cover_image_url }}" alt="" fetchpriority="high" width="1600" height="1000">
    <div class="db-wrap db-hero-inner">
        <div class="db-hero-top">
            <div class="db-strip">
                @foreach ($w->couplePhotos() as $src)
                    <figure class="db-strip-photo">
                        <img src="{{ $src }}" alt="{{ \App\Support\InvitationMediaUrl::photoAlt($event->name) }}" width="600" height="540">
                    </figure>
                @endforeach
            </div>
            <div class="db-hero-copy">
                <p class="db-hero-title">Save<span class="db-script db-hero-the">the</span>Date</p>
                <div class="db-flourish" aria-hidden="true">
                    <svg width="30" height="16" viewBox="0 0 30 16" fill="none" focusable="false"><path d="M15 15V4M15 15L8 7M15 15L22 7M15 15L2 11M15 15L28 11"/></svg>
                </div>
                <p class="db-hero-date">{{ $event->event_date->format('l') }}<br>{{ $event->event_date->format('d F Y') }}</p>
                @include('events.invitations.layouts.wedding_dusty_blue.partials.heart', ['class' => 'db-heart--block'])
                <h1 class="db-hero-names db-script">
                    @if ($nameAfter !== '')
                        {{ $nameBefore }}<span class="db-hero-and">and</span>{{ $nameAfter }}
                    @else
                        {{ $nameBefore }}
                    @endif
                </h1>
            </div>
        </div>

        <p class="db-hero-tag">We can't wait to celebrate our love with you!</p>
        @include('events.invitations.layouts.wedding_dusty_blue.partials.divider')

        <div class="db-panel db-info">
            <div class="db-info-item">
                <span class="db-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false"><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M8 3v4M16 3v4M3.5 10h17"/></svg>
                </span>
                <h2 class="db-info-title">The Date</h2>
                <p>{{ $event->event_date->format('l,') }}<br>{{ $event->event_date->format('F jS, Y') }}</p>
            </div>
            @if ($venueLine !== '' || $locationLine !== '')
                <div class="db-info-item">
                    <span class="db-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false"><path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0c0 5-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/></svg>
                    </span>
                    <h2 class="db-info-title">The Place</h2>
                    <p>{{ $venueLine }}@if ($venueLine !== '' && $locationLine !== '')<br>@endif{{ $locationLine }}</p>
                </div>
            @endif
            <div class="db-info-item">
                <span class="db-icon db-icon--filled" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false"><path d="M12 21s-9-5.6-9-12a5 5 0 0 1 9-3 5 5 0 0 1 9 3c0 6.4-9 12-9 12z"/></svg>
                </span>
                <h2 class="db-info-title">The Feeling</h2>
                <p class="db-italic">Love, laughter,<br>and forever</p>
            </div>
        </div>
    </div>
</section>
