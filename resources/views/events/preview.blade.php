@extends('layouts.site')
@php $hideSiteHeader = true; $hideSiteFooter = true; $guestPage = ! $event->isTicketed(); @endphp

@push('head')
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="{{ asset('css/events-public.css') }}">
@endpush

@unless ($event->isTicketed())
    @include('events.invitations.partials.google-fonts', ['invitation' => $invitation])
    @include('events.invitations.partials.gallery-assets', ['invitation' => $invitation])

    @push('head')
        <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
        <link rel="stylesheet" href="{{ asset('css/events-invitation.css') }}">
        @php $layoutCss = \App\Support\InvitationLayoutVariant::cssFile($invitation['layout_variant'] ?? \App\Support\InvitationLayoutVariant::STANDARD); @endphp
        @if ($layoutCss)
            <link rel="stylesheet" href="{{ asset('css/'.$layoutCss) }}">
        @endif
    @endpush

    @push('scripts')
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

@section('title', 'Preview: '.$event->name.' | '.config('app.name'))

@section('content')

    @unless ($event->isTicketed() || ($appPreview ?? false))
        <div class="evt-preview-bar" role="navigation" aria-label="Invitation preview">
            <a href="{{ $back['route'] }}" class="evt-preview-bar-back">
                <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                <span>{{ $back['label'] }}</span>
            </a>

            <span class="evt-preview-bar-name">{{ $event->name }}</span>

            @if ($event->is_published)
                <span class="evt-preview-bar-note">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    {{ $event->is_public ? 'Live' : 'Published (private)' }}
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

        @if ($previewPalette)
            <div class="evt-preview-palette" role="status">
                <i class="fa-solid fa-palette" aria-hidden="true"></i>
                <span class="evt-preview-palette-text">
                    Previewing the <strong>{{ $previewPalette['label'] }}</strong> palette (not saved)
                </span>
                <a href="{{ route('events.preview', array_filter(['event' => $event, 'from' => request()->query('from') === 'show' ? 'show' : null])) }}" class="evt-preview-palette-reset">
                    Show saved colours
                </a>
            </div>
        @endif
    @endunless

    @unless ($event->is_public)
        <div class="evt-session-banner evt-session-banner--info">
            <i class="fa-solid fa-lock"></i>
            Private event. Guests only ever see this through their personal RSVP link. This page is host-only.
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
            'previewLabel' => $previewPalette
                ? 'Preview: your invitation in the '.$previewPalette['label'].' palette. Guests still see your saved colours until you save this palette.'
                : 'Preview: this is exactly how your invitation looks to guests.',
        ])
    @endif

@endsection
