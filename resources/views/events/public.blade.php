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
    @if ($event->acceptsContributions())
        <link rel="stylesheet" href="{{ asset('css/contributions.css') }}">
    @endif
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

@section('title', $event->name.' | '.config('app.name'))

@section('content')

    <x-event-host-bar :event="$event" />

    @if (session('status') === 'published')
        <div class="evt-session-banner">
            <i class="fa-solid fa-circle-check"></i> Your event is now live.
        </div>
    @endif

    @if ($event->acceptsContributions())
        <div class="ctb-invite-banner">
            <span><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i> This event is accepting contributions of K{{ number_format((float) $event->contribution_amount, 2) }}.</span>
            <a href="{{ route('events.public.contribute', $event->slug) }}" class="ctb-invite-banner-cta">Contribute</a>
        </div>
    @endif

    @include('events.invitations.renderer', ['event' => $event, 'rsvpOpen' => $rsvpOpen, 'rsvpPublicAvailable' => $rsvpPublicAvailable, 'invitation' => $invitation, 'isPreview' => false])

@endsection
