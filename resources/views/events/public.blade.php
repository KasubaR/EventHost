@extends('layouts.site')
@php $hideSiteHeader = true; $hideSiteFooter = true; @endphp

@push('head')
    @include('events.partials.public-invitation-meta', ['event' => $event, 'invitation' => $invitation])
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

@section('title', $event->name.' — '.config('app.name'))

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

    @if (session('status') === 'published')
        <div class="evt-session-banner">
            <i class="fa-solid fa-circle-check"></i> Your event is now live.
        </div>
    @endif

    @include('events.invitations.renderer', ['event' => $event, 'rsvpOpen' => $rsvpOpen, 'rsvpPublicAvailable' => $rsvpPublicAvailable, 'invitation' => $invitation, 'isPreview' => false])

@endsection
