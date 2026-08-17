@extends('layouts.site')
@php $hideSiteHeader = true; $hideSiteFooter = true; @endphp

@push('head')
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="{{ asset('css/events-public.css') }}">
@endpush

@unless ($event->isTicketed())
    @foreach ($invitation['theme']['google_font_families'] as $gf)
        @push('head')
            <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family={{ $gf }}&display=swap">
        @endpush
    @endforeach

    @push('head')
        <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css">
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
@else
    @push('head')
        <link rel="stylesheet" href="{{ asset('css/ticket-checkout.css') }}">
        <link rel="stylesheet" href="{{ asset('css/ticket-event-public.css') }}">
    @endpush
    @push('scripts')
        <script src="{{ asset('js/ticket-event-public.js') }}" defer></script>
    @endpush
@endunless

@section('title', 'Preview — '.$event->name.' | '.config('app.name'))

@section('content')

    <div class="evt-preview-bar" role="navigation" aria-label="Invitation preview">
        <a href="{{ $back['route'] }}" class="evt-preview-bar-back">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            <span>{{ $back['label'] }}</span>
        </a>

        <span class="evt-preview-bar-name">{{ $event->name }}</span>

        @if ($event->isTicketed() && ! $event->is_published)
            <a href="{{ route('events.ticket-types.index', $event) }}" class="evt-preview-bar-publish">
                <i class="fa-solid fa-ticket" aria-hidden="true"></i> Set up tickets
            </a>
        @elseif ($event->is_published)
            <span class="evt-preview-bar-note">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                {{ $event->is_public ? 'Live' : 'Published — private' }}
            </span>
        @else
            <form method="post" action="{{ route('events.publish', $event) }}">
                @csrf
                @method('patch')
                <button type="submit" class="evt-preview-bar-publish">
                    <i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Publish event
                </button>
            </form>
        @endif
    </div>

    @unless ($event->is_public)
        <div class="evt-session-banner evt-session-banner--info">
            <i class="fa-solid fa-lock"></i>
            Private event — guests only ever see this through their personal RSVP link. This page is host-only.
        </div>
    @endunless

    @if ($event->isTicketed())
        @include('events.tickets.partials.landing-content', [
            'event' => $event,
            'isPreview' => true,
        ])
    @else
        @include('events.invitations.renderer', [
            'event' => $event,
            'rsvpOpen' => $rsvpOpen,
            'rsvpPublicAvailable' => $rsvpPublicAvailable,
            'invitation' => $invitation,
            'isPreview' => true,
            'previewLabel' => 'Preview — this is exactly how your invitation looks to guests.',
        ])
    @endif

@endsection
