<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Guest List — {{ $event->name }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; background: #fff; }

        .header { padding: 20px 24px 14px; border-bottom: 2px solid #1a1a1a; margin-bottom: 18px; }
        .header h1 { font-size: 18px; font-weight: 700; margin-bottom: 3px; }
        .header p { font-size: 11px; color: #555; }

        table { width: 100%; border-collapse: collapse; }
        thead tr { background: #1a1a1a; color: #fff; }
        thead th { padding: 7px 8px; text-align: left; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
        tbody tr:nth-child(even) { background: #f5f5f5; }
        tbody td { padding: 6px 8px; border-bottom: 1px solid #e5e5e5; vertical-align: top; }

        .pill { display: inline-block; padding: 2px 7px; border-radius: 10px; font-size: 9px; font-weight: 600; text-transform: uppercase; }
        .pill-accepted  { background: #d1fae5; color: #065f46; }
        .pill-declined  { background: #fee2e2; color: #991b1b; }
        .pill-maybe     { background: #fef9c3; color: #854d0e; }
        .pill-pending   { background: #e5e7eb; color: #374151; }

        .footer { margin-top: 18px; padding-top: 10px; border-top: 1px solid #e5e5e5; font-size: 9px; color: #999; text-align: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Guest List — {{ $event->name }}</h1>
        <p>
            Exported {{ now()->format('F j, Y') }}
            @if ($filterLabel) &nbsp;·&nbsp; Filter: {{ $filterLabel }} @endif
            @if ($checkedInLabel ?? null) &nbsp;·&nbsp; {{ $checkedInLabel }} @endif
            &nbsp;·&nbsp; {{ $guests->count() }} guest(s)
        </p>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Group</th>
                <th>Response</th>
                <th>Attendees</th>
                <th>Message</th>
                <th>Meal</th>
                <th>Checked In</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($guests as $i => $guest)
                @php $rsvp = $guest->rsvp; @endphp
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $guest->name }}</td>
                    <td>{{ $guest->email ?? '—' }}</td>
                    <td>{{ $guest->phone ?? '—' }}</td>
                    <td>{{ $guest->group?->name ?? '—' }}</td>
                    <td>
                        @if ($rsvp)
                            <span class="pill pill-{{ $rsvp->status->value }}">{{ ucfirst($rsvp->status->value) }}</span>
                        @else
                            <span class="pill pill-pending">Pending</span>
                        @endif
                    </td>
                    <td>{{ $rsvp && $rsvp->status->countsTowardGuestLimit() ? $rsvp->attendee_count : '—' }}</td>
                    <td>{{ $rsvp?->message ?? '' }}</td>
                    <td>{{ $rsvp?->meal_preference ?? '' }}</td>
                    <td>
                        @if ($guest->checked_in_at)
                            <span class="pill pill-accepted">{{ $guest->checked_in_at->timezone(config('app.timezone'))->format('M j, g:i A') }}</span>
                        @else
                            <span class="pill pill-pending">Not yet</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">{{ $event->name }} · EventHost</div>
</body>
</html>
