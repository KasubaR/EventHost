@extends('layouts.site')
@php $hideSiteHeader = true; $hideSiteFooter = true; @endphp

@push('head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
    <link rel="stylesheet" href="{{ asset('css/checkin-scanner.css') }}">
@endpush

@section('title', 'Check-in scanner'.($event ? ' | '.$event->name : ''))

@section('content')
    <article class="rsvp-page">
        <div class="rsvp-card">
            <header class="rsvp-header">
                <p class="rsvp-event-badge"><i class="fa-solid fa-qrcode" aria-hidden="true"></i> Door staff scanner</p>
                <h1 class="rsvp-title">{{ $event->name ?? 'Check-in' }}</h1>
                <hr class="rsvp-divider">
            </header>

            @if (! $isActive)
                <p class="tbup-closed">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    This scanner link isn't active. Ask the host for a fresh one.
                </p>
            @else
                {{-- Shared with the guest staff-link scanner (Phase 17) — see
                     events/checkin/partials/scanner-widget.blade.php's doc block. --}}
                @include('events.checkin.partials.scanner-widget', [
                    'kind' => 'ticket',
                    'checkinBase' => url('/checkin/tickets/'.$link->token),
                    // A ticket's own QR always encodes its public buyer page
                    // (Ticket::publicUrl(), the fixed /t prefix), never this
                    // staff-link one — recognizing it here is what lets the same
                    // ticket check in through either scanning path.
                    'selfQrBase' => url('/t'),
                    'lookupUrl' => url('/checkin/tickets/'.$link->token.'/lookup'),
                    'checkInOpen' => $event->isCheckInOpen(),
                    'checkInClosedCopy' => $event->checkInClosedReason(),
                ])
            @endif
        </div>
    </article>
@endsection

@push('scripts')
    <script src="{{ asset('js/vendor/jsqr.min.js') }}" defer></script>
    <script src="{{ asset('js/checkin-scanner.js') }}" defer></script>
@endpush
