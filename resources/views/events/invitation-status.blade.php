@extends('layouts.site')
@php $hideSiteHeader = true; $hideSiteFooter = true; @endphp

@push('head')
    <link rel="stylesheet" href="{{ asset('css/events-public.css') }}">
@endpush

@section('title', $status->title().' | '.config('app.name'))

@section('content')

    <x-event-host-bar :event="$event" />

    <div class="evt-status-page">
        <article class="evt-status-card evt-status-card--{{ $status->value }}">
            <div class="evt-status-icon" aria-hidden="true">
                <i class="fa-solid {{ $status->icon() }}"></i>
            </div>
            <h1 class="evt-status-title">{{ $status->title() }}</h1>
            <p class="evt-status-message">{{ $status->message() }}</p>
            @if (! $event->trashed() && filled($event->name))
                <p class="evt-status-event">{{ $event->name }}</p>
                @if ($event->event_date)
                    <p class="evt-status-date">
                        @if ($status === \App\Enums\PublicInvitationStatus::Ended)
                            Held on {{ $event->event_date->format('l, F j, Y') }}
                        @else
                            {{ $event->event_date->format('l, F j, Y') }}
                        @endif
                    </p>
                @endif
            @endif
            <a href="{{ url('/') }}" class="evt-status-home">Back to {{ config('app.name') }}</a>
        </article>
    </div>

@endsection
