@extends('layouts.site')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
@endpush

@section('title', 'RSVP closed | '.$event->name)

@section('content')
    <article class="rsvp-page evt-public-inner">
        <header class="rsvp-header">
            <h1 class="rsvp-title">{{ $event->name }}</h1>
            <div class="evt-rsvp-banner evt-rsvp-banner--closed rsvp-closed-banner">
                @if ($event->isLocked())
                    <i class="fa-solid fa-champagne-glasses"></i>
                    @if ($guest)
                        Hi {{ $guest->name }}, this event has already taken place.
                    @else
                        This event has already taken place.
                    @endif
                @else
                    <i class="fa-solid fa-clock"></i>
                    @if ($guest)
                        Hi {{ $guest->name }}, the RSVP window for this event is closed.
                    @else
                        The RSVP window for this event is closed.
                    @endif
                @endif
            </div>
            @if ($event->isLocked())
                <p class="rsvp-muted">It was held on {{ $event->event_date->format('l, F j, Y') }}.</p>
            @elseif ($event->rsvp_deadline)
                <p class="rsvp-muted">Deadline was {{ $event->rsvp_deadline->timezone(config('app.timezone'))->format('l, F j, Y \a\t g:i A') }}.</p>
            @endif
        </header>
    </article>
@endsection
