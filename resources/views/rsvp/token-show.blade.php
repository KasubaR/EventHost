@extends('layouts.site')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
@endpush

@section('title', 'RSVP — '.$event->name)

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
                <p class="rsvp-greeting">Hi {{ $guest->name }}, please confirm your plans.</p>
            </header>

            <form method="post" action="{{ route('rsvp.token.store', ['token' => $guest->invitation_token]) }}" class="rsvp-form">
                @csrf
                @include('rsvp.partials.form-fields', [
                    'maxAttendees'   => $maxAttendees,
                    'existingRsvp'   => $existingRsvp ?? null,
                    'rsvpFormConfig' => $rsvpFormConfig ?? [],
                ])
                <button type="submit" class="btn-primary rsvp-submit">
                    <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send my response
                </button>
            </form>
        </div>
    </article>
@endsection
