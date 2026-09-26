<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invitation pass: {{ $card->eventName }}</title>
    {{--
        DomPDF has no CSS variables or flexbox, so the card's theme colours are
        substituted here as literal hex (already validated as #rrggbb by
        GuestPassCard) and the layout is tables. The cover image is left out on
        purpose: covers are stored as WebP, which DomPDF cannot embed.
    --}}
    <style>
        @page { margin: 18px; }
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #1a1a2e;
            margin: 0;
            padding: 0;
            font-size: 12px;
        }
        .pass {
            border: 2px solid {{ $card->theme['primary'] }};
            border-radius: 10px;
            overflow: hidden;
            background-color: {{ $card->theme['background'] }};
        }
        .pass-header {
            background-color: {{ $card->theme['primary'] }};
            color: #ffffff;
            padding: 20px 20px 22px;
        }
        table.brand { border-collapse: collapse; margin: 0 0 14px; }
        table.brand td { padding: 0; vertical-align: middle; }
        .brand-mark { padding-right: 8px; }
        .logo { width: 26px; height: 26px; display: block; }
        .brand-name { font-size: 13px; font-weight: bold; color: #ffffff; }
        .kicker {
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin: 0 0 6px;
        }
        h1 {
            font-size: 24px;
            line-height: 1.2;
            margin: 0;
            color: #ffffff;
        }
        .pass-body { padding: 16px 20px 6px; }
        .meta { font-size: 13px; margin: 0 0 6px; color: #1a1a2e; }
        table.details { width: 100%; border-collapse: collapse; margin: 12px 0 0; }
        table.details td { padding: 7px 0; border-top: 1px solid #e3e3ec; vertical-align: top; }
        td.label {
            width: 38%;
            font-size: 9px;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: #8e8ea8;
            padding-top: 9px;
        }
        td.value { font-size: 14px; font-weight: bold; text-align: right; }
        .perforation { border-top: 1px dashed #b9b9cc; margin: 14px 0 0; }
        .qr-wrap { text-align: center; padding: 18px 20px 20px; }
        .state {
            display: inline-block;
            font-size: 11px;
            font-weight: bold;
            padding: 5px 12px;
            border-radius: 12px;
            margin: 0 0 12px;
        }
        .state--checked-in { background-color: #e6f6ec; color: #1e7a3f; }
        .state--cancelled, .state--ended { background-color: #fdeaea; color: #b3261e; }
        .qr-frame {
            display: inline-block;
            border: 1px solid #d8d8e4;
            border-radius: 8px;
            padding: 10px;
            background-color: #ffffff;
        }
        .qr { width: 170px; height: 170px; display: block; }
        .hint { font-size: 10px; color: #8e8ea8; margin: 10px 0 0; }
    </style>
</head>
<body>
    @php
        // A pass must be exactly one page. Long copy is what breaks that, so scale the
        // title with its length and cap the free-text fields (the web card and the image
        // wrap and ellipsise; a PDF page has a fixed height to defend).
        $titleText = \Illuminate\Support\Str::limit($card->eventName, 140);
        $titleSize = match (true) {
            mb_strlen($titleText) <= 40 => 24,
            mb_strlen($titleText) <= 80 => 19,
            default => 15,
        };
    @endphp
    <div class="pass">
        <div class="pass-header">
            <table class="brand">
                <tr>
                    @if (! empty($logoDataUri))
                        <td class="brand-mark"><img class="logo" src="{{ $logoDataUri }}" alt=""></td>
                    @endif
                    <td class="brand-name">{{ config('app.name') }}</td>
                </tr>
            </table>
            <p class="kicker">{{ $card->eventTypeLabel }} &middot; Invitation</p>
            <h1 style="font-size: {{ $titleSize }}px;">{{ $titleText }}</h1>
        </div>

        <div class="pass-body">
            @if ($card->dateLine)
                <p class="meta">{{ $card->dateLine }}@if ($card->timeLine) &middot; {{ $card->timeLine }}@endif</p>
            @endif
            @if ($card->venue)
                <p class="meta">{{ \Illuminate\Support\Str::limit($card->venue, 120) }}</p>
            @endif

            <table class="details">
                <tr>
                    <td class="label">Guest</td>
                    <td class="value">{{ \Illuminate\Support\Str::limit($card->guestName, 80) }}</td>
                </tr>
                @if ($card->partyLabel())
                    <tr>
                        <td class="label">Plus one</td>
                        <td class="value">{{ $card->partyLabel() }}</td>
                    </tr>
                @endif
                @if ($card->table)
                    <tr>
                        <td class="label">Table</td>
                        <td class="value">{{ \Illuminate\Support\Str::limit($card->table, 40) }}</td>
                    </tr>
                @endif
            </table>
        </div>

        <div class="perforation"></div>

        <div class="qr-wrap">
            @if ($card->stateLabel())
                <p class="state state--{{ str_replace('_', '-', $card->state) }}">{{ $card->stateLabel() }}</p>
            @endif
            <div class="qr-frame">
                <img class="qr" src="{{ $qrDataUri }}" alt="Entry QR code">
            </div>
            @if ($card->isValid())
                <p class="hint">Show this QR code at the door. It is unique to you, please don't share it.</p>
            @endif
        </div>
    </div>
</body>
</html>
