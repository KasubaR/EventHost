<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Table QR codes — {{ $event->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1a1a1a; margin: 0; padding: 20px; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        p.sub { font-size: 11px; color: #666; margin: 0 0 18px; }
        .card {
            display: inline-block;
            width: 260px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px;
            margin: 0 12px 16px 0;
            text-align: center;
        }
        .card .qr { width: 180px; height: 180px; }
        .card .label { font-size: 15px; font-weight: bold; margin: 10px 0 2px; }
        .card .code { font-size: 10px; color: #888; letter-spacing: 1px; }
    </style>
</head>
<body>
    <h1>{{ $event->name }} — Table QR codes</h1>
    <p class="sub">Print, cut, and place one card per table. Guests scan to add photos — no app or login needed.</p>

    @foreach ($tables as $table)
        <div class="card">
            <img class="qr" src="{{ $table['qr_data_uri'] }}" alt="QR code for {{ $table['label'] }}">
            <div class="label">{{ $table['label'] }}</div>
            <div class="code">{{ $table['code'] }}</div>
        </div>
    @endforeach
</body>
</html>
