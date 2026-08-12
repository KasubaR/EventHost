<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Guest check-in QR codes — {{ $event->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1a1a1a; margin: 0; padding: 20px; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        p.sub { font-size: 11px; color: #666; margin: 0 0 18px; }
        .card {
            display: inline-block;
            width: 220px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 14px;
            margin: 0 10px 14px 0;
            text-align: center;
        }
        .card .qr { width: 150px; height: 150px; }
        .card .label { font-size: 13px; font-weight: bold; margin: 8px 0 0; }
    </style>
</head>
<body>
    <h1>{{ $event->name }} — Guest check-in badges</h1>
    <p class="sub">One QR per invited guest. Print, cut, and hand out as entrance badges — staff scan these to check guests in.</p>

    @foreach ($guests as $guest)
        <div class="card">
            <img class="qr" src="{{ $guest['qr_data_uri'] }}" alt="Check-in QR for {{ $guest['name'] }}">
            <div class="label">{{ $guest['name'] }}</div>
        </div>
    @endforeach
</body>
</html>
