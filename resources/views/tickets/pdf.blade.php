<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Ticket: {{ $ticket->event->name }}</title>
    <style>
        @page { margin: 18px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1a1a1a;
            margin: 0;
            padding: 0;
            font-size: 12px;
        }
        .ticket {
            border: 2px solid #1e47bb;
            border-radius: 10px;
            overflow: hidden;
        }
        .ticket-accent {
            height: 8px;
            background-color: #1e47bb;
        }
        .ticket-header {
            background-color: #ffffff;
            padding: 14px 18px 10px;
            border-bottom: 1px solid #e0e0e8;
        }
        .ticket-header-table {
            width: 100%;
            border-collapse: collapse;
        }
        .ticket-header-table td {
            vertical-align: middle;
            padding: 0;
        }
        .ticket-logo {
            height: 28px;
            width: auto;
        }
        .brand-fallback {
            font-size: 14px;
            font-weight: bold;
            color: #1e47bb;
        }
        .type-badge-cell {
            text-align: right;
        }
        .type-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 5px 12px;
            border-radius: 999px;
            border: 1px solid transparent;
        }
        .type-badge--standard { background-color: #eef1fb; color: #1e47bb; border-color: #c7d2f5; }
        .type-badge--vip { background-color: #fdf3d9; color: #92650c; border-color: #f0d68a; }
        .type-badge--premium { background-color: #f1e8fb; color: #5b2a99; border-color: #d9c3f0; }
        .type-badge--early { background-color: #e3f6f2; color: #0e766e; border-color: #a8e0d4; }
        .type-badge--group { background-color: #fdeaf0; color: #a8104a; border-color: #f3b8cf; }
        .ticket-body {
            padding: 18px 18px 14px;
        }
        h1 {
            font-size: 20px;
            margin: 0 0 10px;
            line-height: 1.25;
            color: #0d0d2b;
        }
        .meta {
            font-size: 12px;
            color: #444;
            margin: 0 0 4px;
        }
        .perforation {
            border-top: 1px dashed #c5c5d4;
            margin: 16px 0;
        }
        .qr-wrap {
            text-align: center;
            margin: 0 0 16px;
        }
        .qr-frame {
            display: inline-block;
            border: 1px solid #d8d8e4;
            border-radius: 8px;
            padding: 10px;
            background: #ffffff;
        }
        .qr {
            width: 180px;
            height: 180px;
            display: block;
        }
        table.details {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 14px;
        }
        table.details td {
            padding: 7px 0;
            font-size: 12px;
            border-top: 1px solid #ececf2;
        }
        table.details td.label {
            color: #8e8ea8;
            width: 42%;
            font-weight: normal;
        }
        table.details td.value {
            font-weight: bold;
            color: #0d0d2b;
            text-align: right;
        }
        .stub {
            background-color: #f5f6fb;
            border: 1px solid #e0e0e8;
            border-radius: 6px;
            padding: 10px 12px;
            text-align: center;
            margin: 0 0 12px;
        }
        .stub-label {
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #8e8ea8;
            margin: 0 0 4px;
        }
        .stub-ref {
            font-family: DejaVu Sans Mono, monospace;
            font-size: 13px;
            font-weight: bold;
            color: #1e47bb;
            margin: 0;
            letter-spacing: 0.5px;
        }
        .footer {
            margin: 0;
            font-size: 10px;
            color: #8e8ea8;
            text-align: center;
            line-height: 1.4;
        }
    </style>
</head>
    @php
        $ticketTypeName = $ticket->ticketType?->name ?? 'General';
        $ticketTypeBadgeVariant = $ticket->ticketType?->badge_color ?? \App\Models\TicketType::DEFAULT_BADGE_COLOR;
        if (! array_key_exists($ticketTypeBadgeVariant, \App\Models\TicketType::BADGE_COLORS)) {
            $ticketTypeBadgeVariant = \App\Models\TicketType::DEFAULT_BADGE_COLOR;
        }
    @endphp
<body>
    <div class="ticket">
        <div class="ticket-accent"></div>
        <div class="ticket-header">
            <table class="ticket-header-table">
                <tr>
                    <td>
                        @if (! empty($logoDataUri))
                            <img class="ticket-logo" src="{{ $logoDataUri }}" alt="{{ config('app.name') }}">
                        @else
                            <span class="brand-fallback">{{ config('app.name') }}</span>
                        @endif
                    </td>
                    <td class="type-badge-cell">
                        <span class="type-badge type-badge--{{ $ticketTypeBadgeVariant }}">{{ $ticketTypeName }}</span>
                    </td>
                </tr>
            </table>
        </div>

        <div class="ticket-body">
            <h1>{{ $ticket->event->name }}</h1>
            <p class="meta">{{ $ticket->event->event_date->format('l, F j, Y') }}
                @if ($ticket->event->event_time)
                    &middot; {{ \Illuminate\Support\Str::substr($ticket->event->event_time, 0, 5) }}
                @endif
            </p>
            @if ($ticket->event->venue)
                <p class="meta">{{ $ticket->event->venue }}</p>
            @endif

            <div class="perforation"></div>

            <div class="qr-wrap">
                <div class="qr-frame">
                    <img class="qr" src="{{ $qrDataUri }}" alt="Ticket QR code">
                </div>
            </div>

            <table class="details">
                <tr>
                    <td class="label">Attendee</td>
                    <td class="value">{{ $ticket->attendee_name }}</td>
                </tr>
                <tr>
                    <td class="label">Ticket</td>
                    <td class="value">{{ $ticket->ticketType?->name ?? 'Ticket' }}</td>
                </tr>
                <tr>
                    <td class="label">Order reference</td>
                    <td class="value">{{ $ticket->order->order_reference }}</td>
                </tr>
                <tr>
                    <td class="label">Status</td>
                    <td class="value">{{ $ticket->status->label() }}</td>
                </tr>
            </table>

            <div class="stub">
                <p class="stub-label">Order reference</p>
                <p class="stub-ref">{{ $ticket->order->order_reference }}</p>
            </div>

            <p class="footer">Show this QR code at the door. This ticket is unique to you, please don't share it.</p>
        </div>
    </div>
</body>
</html>
