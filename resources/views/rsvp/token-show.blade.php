{{--
    Personal per-guest RSVP link. Renders the same designed invitation as the
    public link (events/public.blade.php → events.invitations.renderer) — a guest
    with only this link (private events have no public page at all) still needs
    to see the hero/description/gallery to know what they're accepting or
    declining, not a bare form. See app/Http/Controllers/RsvpController::showByToken().

    No public-invitation-meta include here on purpose: that partial points
    canonical/og:url at events.public, which 403s for private events — this page
    is a personal link, not something meant to be shared/indexed.
--}}
@extends('layouts.site')
@php $hideSiteHeader = true; $hideSiteFooter = true; @endphp

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

@foreach ($invitation['theme']['google_font_families'] as $gf)
    @push('head')
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family={{ $gf }}&display=swap">
    @endpush
@endforeach

@push('head')
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css">
    <link rel="stylesheet" href="{{ asset('css/events-public.css') }}">
    <link rel="stylesheet" href="{{ asset('css/events-invitation.css') }}">
    @php $layoutCss = \App\Support\InvitationLayoutVariant::cssFile($invitation['layout_variant'] ?? \App\Support\InvitationLayoutVariant::STANDARD); @endphp
    @if ($layoutCss)
        <link rel="stylesheet" href="{{ asset('css/'.$layoutCss) }}">
    @endif
@endpush

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js" defer></script>
    <script src="{{ asset('js/invitation-public.js') }}" defer></script>
@endpush

@section('title', 'RSVP | '.$event->name)

@section('content')

    <div class="evt-host-bar">
        <a href="{{ url('/') }}" class="evt-host-bar-logo" target="_blank" rel="noopener noreferrer">
            <img src="{{ asset('images/logo/EventHost Logo_Icon.svg') }}" alt="{{ config('app.name') }}" width="22" height="22">
            <span>{{ config('app.name') }}</span>
        </a>
        <p class="evt-host-bar-tagline">Create beautiful event invitations &amp; track RSVPs in one place.</p>
        <a href="{{ url('/') }}" class="evt-host-bar-cta" target="_blank" rel="noopener noreferrer">
            Get started free <i class="fa-solid fa-arrow-right"></i>
        </a>
    </div>

    @include('events.invitations.renderer', [
        'event' => $event,
        // Not the public/open flow — the rsvp section renders the personal token
        // form instead as soon as it sees $guest, regardless of these two.
        'rsvpOpen' => true,
        'rsvpPublicAvailable' => false,
        'invitation' => $invitation,
        'isPreview' => false,
        'guest' => $guest,
        'existingRsvp' => $existingRsvp ?? null,
        'maxAttendees' => $maxAttendees ?? 1,
        'showEntryPass' => $showEntryPass ?? false,
    ])

@endsection
