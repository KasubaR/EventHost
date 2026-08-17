<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Ticket — {{ $ticket->event->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1a1a1a; margin: 0; padding: 28px; }
        .badge { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #666; margin: 0 0 6px; }
        h1 { font-size: 20px; margin: 0 0 14px; }
        .meta { font-size: 12px; color: #444; margin: 0 0 3px; }
        .qr-wrap { text-align: center; margin: 22px 0; }
        .qr { width: 200px; height: 200px; border: 1px solid #ddd; border-radius: 8px; padding: 10px; }
        table.details { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.details td { padding: 6px 0; font-size: 12px; border-top: 1px solid #eee; }
        table.details td.label { color: #888; width: 40%; }
        table.details td.value { font-weight: bold; }
        .footer { margin-top: 20px; font-size: 10px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <p class="badge">{{ $ticket->ticketType?->name ?? 'Ticket' }}</p>
    <h1>{{ $ticket->event->name }}</h1>
    <p class="meta">{{ $ticket->event->event_date->format('l, F j, Y') }}
        @if ($ticket->event->event_time)
            &middot; {{ \Illuminate\Support\Str::substr($ticket->event->event_time, 0, 5) }}
        @endif
    </p>
    @if ($ticket->event->venue)
        <p class="meta">{{ $ticket->event->venue }}</p>
    @endif

    <div class="qr-wrap">
        <img class="qr" src="{{ $qrDataUri }}" alt="Ticket QR code">
    </div>

    <table class="details">
        <tr>
            <td class="label">Attendee</td>
            <td class="value">{{ $ticket->attendee_name }}</td>
        </tr>
        <tr>
            <td class="label">Ticket</td>
            <td class="value">{{ $ticket->ticketType?->name ?? '—' }}</td>
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

    <p class="footer">Show this QR code at the door. This ticket is unique to you — please don't share it.</p>
</body>
</html>
