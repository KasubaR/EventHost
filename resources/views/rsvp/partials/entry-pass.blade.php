{{-- Shown only when RsvpController::guestHasEntryPass() passed — see callers. --}}
@if ($showEntryPass ?? false)
    <div class="rsvp-pass">
        <p class="rsvp-pass-badge"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> You're going!</p>
        <p class="rsvp-pass-lead">Show this at the door</p>
        <img src="{{ route('rsvp.token.entry-pass', ['token' => $guest->invitation_token]) }}"
             alt="Your entry QR code" class="rsvp-pass-qr" width="200" height="200" loading="lazy">
        <p class="rsvp-pass-name">{{ $guest->name }}</p>
        @if ($guest->tableLabel())
            <p class="rsvp-pass-table"><i class="fa-solid fa-chair" aria-hidden="true"></i> {{ $guest->tableLabel() }}</p>
        @endif
        <a href="{{ route('rsvp.token.entry-pass', ['token' => $guest->invitation_token, 'download' => 1]) }}"
           class="rsvp-pass-download"
           download="{{ \Illuminate\Support\Str::slug($guest->name) }}-entry-qr.svg">
            <i class="fa-solid fa-download" aria-hidden="true"></i>
            Download QR code
        </a>
    </div>
@endif
