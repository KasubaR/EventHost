@extends('layouts.site')
@php $guestPage = true; @endphp

@push('head')
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
@endpush

@section('title', 'RSVP closed | '.$event->name)

@section('content')
    <article class="rsvp-page evt-public-inner">
        <header class="rsvp-header">
            <h1 class="rsvp-title">{{ $event->name }}</h1>
            {{-- Set by the RsvpClosedException renderer when a late submit lands here. --}}
            @if (session('rsvp_closed'))
                <div class="evt-rsvp-banner evt-rsvp-banner--closed rsvp-closed-notice" role="alert">
                    <i class="fa-solid fa-circle-info"></i> {{ session('rsvp_closed') }}
                </div>
            @endif
            <div class="evt-rsvp-banner evt-rsvp-banner--closed rsvp-closed-banner">
                @if ($guestListFull ?? false)
                    <i class="fa-solid fa-users"></i>
                    This event's guest list is full. The host isn't accepting new RSVPs from this link right now.
                @elseif ($event->isLocked())
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
            @unless ($guestListFull ?? false)
                @if ($event->isLocked())
                    <p class="rsvp-muted">It was held on {{ $event->event_date->format('l, F j, Y') }}.</p>
                @elseif ($event->rsvp_deadline)
                    <p class="rsvp-muted">Deadline was {{ $event->rsvpDeadlineLabel() }}.</p>
                @endif
            @endunless
        </header>

        {{-- Past the deadline a guest who already answered can still cancel or take fewer seats until the
             event starts. The server enforces what counts as a reduction; these are just the two actions. --}}
        @if ($guest && ($canReduce ?? false))
            <section class="rsvp-card rsvp-reduce">
                <h2 class="rsvp-reduce-title">Can no longer come?</h2>
                <p class="rsvp-muted">The deadline has passed, but you can still change your response until the event starts.</p>

                @if ($guest->rsvp?->attendee_count > 1)
                    <form method="POST" action="{{ route('rsvp.token.store', ['token' => $guest->invitation_token]) }}" class="rsvp-reduce-form">
                        @csrf
                        <input type="hidden" name="status" value="accepted">
                        <input type="hidden" name="attendee_count" value="1">
                        <button type="submit" class="rsvp-thanks-btn">Only me, not my plus-one</button>
                    </form>
                @endif

                <form method="POST" action="{{ route('rsvp.token.store', ['token' => $guest->invitation_token]) }}" class="rsvp-reduce-form">
                    @csrf
                    <input type="hidden" name="status" value="declined">
                    <input type="hidden" name="attendee_count" value="0">
                    <button type="submit" class="rsvp-thanks-btn">Cancel my RSVP</button>
                </form>
            </section>
        @endif

        @if ($guest)
            @include('rsvp.partials.entry-pass', ['guest' => $guest, 'showEntryPass' => $showEntryPass ?? false])
        @endif

        @include('rsvp.partials.host-contact', ['event' => $event])
    </article>
@endsection
