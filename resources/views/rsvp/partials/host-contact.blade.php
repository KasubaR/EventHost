{{-- plans/group-rsvp-links.md — the host's number on every guest-facing RSVP page. Renders nothing when the
     event has none (older events), so there is never a dead "call null". --}}
@php
    $hostPhone = $event?->hostContactPhone();
    $hostName = $event?->user?->name;
@endphp
@if ($hostPhone)
    <p class="rsvp-host-contact">
        <i class="fa-solid fa-phone" aria-hidden="true"></i>
        Questions?
        Call @if ($hostName){{ $hostName }} on @endif<a href="tel:{{ preg_replace('/[^\d+]/', '', $hostPhone) }}">{{ $hostPhone }}</a>.
    </p>
@endif
