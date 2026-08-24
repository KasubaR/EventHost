@extends('layouts.site')
@php $hideSiteHeader = true; $hideSiteFooter = true; @endphp

@push('head')
    <link rel="stylesheet" href="{{ asset('css/events-public.css') }}">
@endpush

@section('title', $status->title().' | '.config('app.name'))

@section('content')

    <div class="evt-host-bar">
        <a href="{{ url('/') }}" class="evt-host-bar-logo" target="_blank" rel="noopener noreferrer">
            <img src="{{ asset('images/logo/EventHost Logo_Icon.svg') }}" alt="{{ config('app.name') }}" width="22" height="22">
            <span>{{ config('app.name') }}</span>
        </a>
        <p class="evt-host-bar-tagline">Create beautiful event invitations &amp; track RSVPs in one place.</p>
        <a href="{{ url('/') }}" class="evt-host-bar-cta" target="_blank" rel="noopener noreferrer">
            Get started free <i class="fa-solid fa-arrow-right"></i>
        </a>
    </div>

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
