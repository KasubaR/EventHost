@extends('layouts.site')

@section('title', 'Discover events | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/event-cards.css') }}">
@endpush

@section('content')

<div class="discover-hero">
    <h1>Discover upcoming events</h1>
    <p>Public invitations from hosts on Event Host. Tap any event to view it and RSVP.</p>
</div>

<div class="discover-wrap">
    @if ($events->isEmpty())
        <div class="discover-empty">
            <i class="fa-regular fa-calendar" aria-hidden="true"></i>
            <h2>No upcoming public events yet</h2>
            <p>Once hosts publish public invitations, they will show up here.</p>
            <a href="{{ route('register') }}" class="btn-hero-primary">Create your event</a>
        </div>
    @else
        <div class="event-card-grid">
            @foreach ($events as $event)
                @include('events.partials.public-event-card', ['event' => $event])
            @endforeach
        </div>

        <div class="discover-pagination">
            {{ $events->onEachSide(1)->links() }}
        </div>
    @endif
</div>

@endsection
