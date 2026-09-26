{{--
    The guest's digital invitation card. Pure presentation of a GuestPassCard —
    every value shown here comes from that object so the PDF and PNG renderers
    (plans/invitation-pass-card.md, Phases 2–3) can print exactly the same thing.

    Inputs: $card (App\Support\GuestPassCard), $guest (for the QR route token).
--}}
@once
    @push('head')
        <link rel="stylesheet" href="{{ asset('css/guest-pass.css') }}">
    @endpush
@endonce

@php
    // A void state (cancelled / ended) greys the QR out; checked-in keeps it
    // readable — a guest stepping out and back in must still be scannable.
    $qrDimmed = in_array($card->state, [\App\Support\GuestPassCard::STATE_CANCELLED, \App\Support\GuestPassCard::STATE_ENDED], true);
@endphp

<article class="gpass"
         style="--gp-primary: {{ $card->theme['primary'] }}; --gp-accent: {{ $card->theme['accent'] }}; --gp-bg: {{ $card->theme['background'] }};"
         aria-label="Invitation pass for {{ $card->eventName }}">
    <header class="gpass-header">
        @if ($card->coverUrl)
            <img src="{{ $card->coverUrl }}" alt="" class="gpass-cover" loading="lazy">
        @endif
        <div class="gpass-header-body">
            <p class="gpass-kicker"><i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i> {{ $card->eventTypeLabel }} &middot; Invitation</p>
            <h2 class="gpass-title">{{ $card->eventName }}</h2>
        </div>
    </header>

    <div class="gpass-body">
        <ul class="gpass-meta">
            @if ($card->dateLine)
                <li>
                    <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                    <span>{{ $card->dateLine }}@if ($card->timeLine) &middot; {{ $card->timeLine }}@endif</span>
                </li>
            @endif
            @if ($card->venue)
                <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <span>{{ $card->venue }}</span></li>
            @endif
        </ul>

        <dl class="gpass-details">
            <div>
                <dt>Guest</dt>
                <dd>{{ $card->guestName }}</dd>
            </div>
            @if ($card->partyLabel())
                <div>
                    <dt>Plus one</dt>
                    <dd>{{ $card->partyLabel() }}</dd>
                </div>
            @endif
            @if ($card->table)
                <div>
                    <dt>Table</dt>
                    <dd>{{ $card->table }}</dd>
                </div>
            @endif
        </dl>
    </div>

    <div class="gpass-tear" aria-hidden="true"></div>

    <div class="gpass-qr-wrap">
        @if ($card->stateLabel())
            <p class="gpass-state gpass-state--{{ str_replace('_', '-', $card->state) }}">
                <i class="fa-solid {{ $card->state === \App\Support\GuestPassCard::STATE_CHECKED_IN ? 'fa-circle-check' : 'fa-ban' }}" aria-hidden="true"></i>
                {{ $card->stateLabel() }}
            </p>
        @endif
        <img src="{{ route('rsvp.token.entry-pass', ['token' => $guest->invitation_token]) }}"
             alt="Entry QR code for {{ $card->guestName }} — {{ $card->eventName }}"
             class="gpass-qr @if ($qrDimmed) gpass-qr--dimmed @endif"
             width="200" height="200" loading="lazy">
        @if ($card->isValid())
            <p class="gpass-hint">Show this QR code at the door</p>
        @endif
    </div>
</article>
