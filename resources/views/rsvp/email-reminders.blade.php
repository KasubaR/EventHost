@extends('layouts.site')
@php $guestPage = true; @endphp

@push('head')
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
@endpush

@section('title', 'Reminder emails')

@section('content')
    {{-- Reached from a link in a reminder email. Deliberately shows only the event's name: whoever holds the
         link is not necessarily the guest, so nothing about the guest is echoed back. --}}
    <article class="rsvp-page evt-public-inner">
        <header class="rsvp-header">
            <h1 class="rsvp-title">{{ $event?->name ?? 'Reminder emails' }}</h1>

            @if ($state === 'confirm')
                <p class="rsvp-muted">
                    Stop the reminder emails for {{ $event ? $event->name : 'this event' }}? You will still be able to
                    open your invitation and your pass from the links you already have.
                </p>
                <form method="post" action="{{ $stopUrl }}">
                    <button type="submit" class="btn-primary rsvp-submit">Stop reminder emails</button>
                </form>
            @elseif ($state === 'stopped')
                <div class="evt-rsvp-banner rsvp-closed-banner">
                    <i class="fa-solid fa-bell-slash"></i>
                    You will not get any more reminder emails for {{ $event ? $event->name : 'this event' }}.
                </div>
                <p class="rsvp-muted">Changed your mind?</p>
                <form method="post" action="{{ $resumeUrl }}">
                    <button type="submit" class="btn-primary rsvp-submit">Send me reminders again</button>
                </form>
            @else
                <div class="evt-rsvp-banner rsvp-closed-banner">
                    <i class="fa-solid fa-bell"></i>
                    Reminder emails are back on for {{ $event ? $event->name : 'this event' }}.
                </div>
            @endif
        </header>
    </article>
@endsection
