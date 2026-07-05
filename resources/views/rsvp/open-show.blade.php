@extends('layouts.site')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
@endpush

@section('title', 'RSVP | '.$event->name)

@section('content')
    <article class="rsvp-page">
        <div class="rsvp-card">
            <header class="rsvp-header">
                <p class="rsvp-event-badge"><i class="fa-solid fa-envelope" aria-hidden="true"></i> Invitation</p>
                <h1 class="rsvp-title">{{ $event->name }}</h1>
                <ul class="rsvp-meta">
                    <li>
                        <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                        {{ $event->event_date->format('l, F j, Y') }}
                        @if ($event->event_time)
                            &middot; {{ \Illuminate\Support\Str::substr($event->event_time, 0, 5) }}
                        @endif
                    </li>
                    @if ($event->venue)
                        <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $event->venue }}</li>
                    @endif
                </ul>
                <hr class="rsvp-divider">
                <p class="rsvp-lead">Enter your details and let the host know if you can make it.</p>
            </header>

            @include('rsvp.partials.open-rsvp-form', ['event' => $event, 'maxAttendees' => $maxAttendees])
        </div>
    </article>
@endsection
