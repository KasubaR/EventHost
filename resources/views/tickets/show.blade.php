@extends('layouts.site')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/ticket-checkout.css') }}">
@endpush

@section('title', 'Your ticket | '.$ticket->event->name)

@section('content')
    @php
        // Cancelled/refunded are refused at the door outright; a used ticket can
        // still legitimately be re-scanned (someone stepping out and returning),
        // so the two states are marked differently rather than lumped together.
        $isVoid = in_array($ticket->status, [\App\Enums\TicketStatus::Cancelled, \App\Enums\TicketStatus::Refunded], true);
        $isCheckedIn = ! $isVoid && $ticket->isCheckedIn();
        $qrAlt = match (true) {
            $isVoid => 'Ticket QR code — '.$ticket->status->label(),
            $isCheckedIn => 'Ticket QR code — already checked in',
            default => 'Ticket QR code',
        };
    @endphp

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
                <div class="tkc-qr-frame @if ($isVoid) tkc-qr-frame--void @elseif ($isCheckedIn) tkc-qr-frame--used @endif">
                    @if ($isVoid)
                        <p class="tkc-qr-stamp">
                            <i class="fa-solid fa-ban" aria-hidden="true"></i>
                            {{ $ticket->status->label() }} — not valid for entry
                        </p>
                    @elseif ($isCheckedIn)
                        <p class="tkc-qr-stamp">
                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                            Checked in {{ $ticket->checked_in_at->timezone(config('app.timezone'))->format('j M, g:i A') }}
                        </p>
                    @endif
                    <div class="tkc-qr-code">
                        <img src="{{ route('tickets.qr', $ticket->public_token) }}" alt="{{ $qrAlt }}" class="tkc-qr-img">
                        {{-- Laid over the modules themselves, so the state survives a
                             crop of just the code. Safe because ticket QRs render at
                             high error correction — see QrCodeService::ECC_HIGH. --}}
                        @if ($isVoid)
                            <span class="tkc-qr-badge tkc-qr-badge--void" aria-hidden="true">{{ $ticket->status->label() }}</span>
                        @elseif ($isCheckedIn)
                            <span class="tkc-qr-badge tkc-qr-badge--used" aria-hidden="true">Used</span>
                        @endif
                    </div>
                </div>
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

            <p class="tkc-muted">
                @if ($isVoid)
                    This ticket is no longer valid for entry. Contact the organiser if you think this is wrong.
                @elseif ($isCheckedIn)
                    This ticket has already been used for entry. Scanning it again will show door staff that it was already checked in.
                @else
                    Show this QR code at the door. Save this page or the email it was sent to.
                @endif
            </p>

            @php
                $waUrl = $ticket->attendee_phone
                    ? \App\Support\WhatsAppInviteLink::url(
                        $ticket->attendee_phone,
                        \App\Support\WhatsAppInviteLink::ticketConfirmationMessage(
                            $ticket->event->name,
                            $ticket->attendee_name ?? '',
                            $ticket->ticketType?->name ?? 'Ticket',
                            $ticket->event->event_date->format('j F'),
                            $ticket->event->venue,
                            $ticket->publicUrl(),
                        )
                    )
                    : null;
            @endphp
            <div class="tkc-ticket-actions">
                <a href="{{ route('tickets.download', $ticket->public_token) }}" class="evt-btn-outline"><i class="fa-solid fa-download" aria-hidden="true"></i> Download ticket</a>
                @if ($waUrl)
                    <a href="{{ $waUrl }}" target="_blank" rel="noopener" class="evt-btn-outline"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i> Send to WhatsApp</a>
                @endif
            </div>
        </div>
    </article>
@endsection
