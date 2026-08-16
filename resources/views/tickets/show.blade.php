@extends('layouts.site')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/ticket-checkout.css') }}">
@endpush

@section('title', 'Your ticket | '.$ticket->event->name)

@section('content')
    <article class="tkc-page">
        <div class="tkc-card tkc-card--narrow tkc-ticket-card">
            <header class="tkc-header">
                <p class="tkc-event-badge"><i class="fa-solid fa-ticket" aria-hidden="true"></i> {{ $ticket->ticketType?->name ?? 'Ticket' }}</p>
                <h1 class="tkc-title">{{ $ticket->event->name }}</h1>
                <ul class="tkc-meta">
                    <li>
                        <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                        {{ $ticket->event->event_date->format('l, F j, Y') }}
                        @if ($ticket->event->event_time)
                            &middot; {{ \Illuminate\Support\Str::substr($ticket->event->event_time, 0, 5) }}
                        @endif
                    </li>
                    @if ($ticket->event->venue)
                        <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $ticket->event->venue }}</li>
                    @endif
                </ul>
            </header>

            <div class="tkc-qr-wrap">
                <img src="{{ route('tickets.qr', $ticket->public_token) }}" alt="Ticket QR code" class="tkc-qr-img">
            </div>

            <dl class="tkc-ticket-details">
                <div>
                    <dt>Attendee</dt>
                    <dd>{{ $ticket->attendee_name }}</dd>
                </div>
                <div>
                    <dt>Ticket</dt>
                    <dd>{{ $ticket->ticketType?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Order reference</dt>
                    <dd>{{ $ticket->order->order_reference }}</dd>
                </div>
                <div>
                    <dt>Status</dt>
                    <dd>{{ $ticket->status->label() }}</dd>
                </div>
            </dl>

            <p class="tkc-muted">Show this QR code at the door. Save this page or the email it was sent to.</p>
        </div>
    </article>
@endsection
