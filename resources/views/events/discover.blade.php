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
    <form method="get" action="{{ route('events.discover') }}" class="discover-filters" role="search">
        <div class="discover-filter-field discover-filter-field--search">
            <label for="discover-q" class="discover-sr-only">Search events</label>
            <input id="discover-q" type="search" name="q" value="{{ $search }}" placeholder="Search events by name">
        </div>
        <div class="discover-filter-field">
            <label for="discover-type" class="discover-sr-only">Event type</label>
            <select id="discover-type" name="type">
                <option value="">All types</option>
                @foreach ($eventTypes as $eventType)
                    <option value="{{ $eventType }}" @selected($type === $eventType)>{{ \App\Models\Event::TYPE_LABELS[$eventType] ?? $eventType }}</option>
                @endforeach
            </select>
        </div>
        <div class="discover-filter-field">
            <label for="discover-when" class="discover-sr-only">When</label>
            <select id="discover-when" name="when">
                <option value="">Any time</option>
                <option value="today" @selected($when === 'today')>Today</option>
                <option value="week" @selected($when === 'week')>Next 7 days</option>
                <option value="month" @selected($when === 'month')>This month</option>
            </select>
        </div>
        <div class="discover-filter-field">
            <label for="discover-where" class="discover-sr-only">City or venue</label>
            <input id="discover-where" type="text" name="where" value="{{ $where }}" placeholder="City or venue">
        </div>
        <button type="submit" class="btn-hero-primary discover-filter-submit">Search</button>
        @if ($hasActiveFilters)
            <a href="{{ route('events.discover') }}" class="discover-filter-clear">Clear filters</a>
        @endif
    </form>

    @if ($events->isEmpty())
        <div class="discover-empty">
            <i class="fa-regular fa-calendar" aria-hidden="true"></i>
            @if ($hasActiveFilters)
                <h2>No events match these filters</h2>
                <p>Try widening your search or clearing a filter.</p>
                <a href="{{ route('events.discover') }}" class="btn-hero-primary">Clear filters</a>
            @else
                <h2>No upcoming public events yet</h2>
                <p>Once hosts publish public invitations, they will show up here.</p>
                <a href="{{ route('register') }}" class="btn-hero-primary">Create your event</a>
            @endif
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
