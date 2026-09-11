{{--
    The fixed public page for every ticketed event — no invitation layout to
    choose (see plans/ticketing.md §4). Structurally mirrors
    events/public.blade.php (same host bar / session banner chrome) but skips
    the invitation-template system entirely; the actual content lives in the
    shared events.tickets.partials.landing-content partial so this page and
    the host-only preview (events/preview.blade.php) never drift apart.
--}}
@extends('layouts.site')
@php $hideSiteHeader = true; $hideSiteFooter = true; @endphp

@push('head')
    <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags((string) $event->description), 160) ?: 'Tickets for '.$event->name.'.' }}">
    <link rel="stylesheet" href="{{ asset('css/events-public.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ticket-checkout.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ticket-event-public.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/ticket-event-public.js') }}" defer></script>
@endpush

@section('title', $event->name.' | '.config('app.name'))

@section('content')

    <x-event-host-bar :event="$event" tagline="Create beautiful ticketed events & sell tickets in one place." />

    @include('events.tickets.partials.landing-content', ['event' => $event, 'isPreview' => false])

@endsection
