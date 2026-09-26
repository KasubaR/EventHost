{{-- Shown only when RsvpController::guestHasEntryPass() passed — see callers. --}}
@if ($showEntryPass ?? false)
    @php
        $passEvent = $event ?? $guest->event;
        $passCard = \App\Support\GuestPassCard::for($guest, $passEvent, theme: $invitation['theme'] ?? null);
    @endphp
    <div class="gpass-panel">
        <p class="gpass-panel-badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> You're going!</p>

        @include('rsvp.partials.pass-card', ['card' => $passCard, 'guest' => $guest])

        <div class="gpass-actions">
            <a href="{{ route('rsvp.token.pass', ['token' => $guest->invitation_token]) }}" class="rsvp-pass-download">
                <i class="fa-solid fa-id-card" aria-hidden="true"></i>
                Open full pass
            </a>
            <a href="{{ route('rsvp.token.pass-download', ['token' => $guest->invitation_token]) }}" class="rsvp-pass-download">
                <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
                Download PDF
            </a>
            <a href="{{ route('rsvp.token.pass-image', ['token' => $guest->invitation_token, 'download' => 1]) }}" class="rsvp-pass-download">
                <i class="fa-solid fa-image" aria-hidden="true"></i>
                Save as image
            </a>
        </div>
    </div>
@endif
