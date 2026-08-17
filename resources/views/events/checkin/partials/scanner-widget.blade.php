{{--
    Shared camera-scan + manual-lookup widget for BOTH credential types
    (Phase 17 — guests and tickets, never both on one event since product_kind
    is mutually exclusive). One file, one set of DOM ids/classes, driven by
    $kind ('guest' default | 'ticket') — checkin-scanner.js reads
    data-checkin-kind and switches its result-field mapping and copy from
    there. Business logic stays split (CheckInService vs TicketCheckInService,
    CheckInController vs TicketCheckInController) — only this markup/JS layer
    is shared, which is what actually eliminated the duplication (the two
    were ~95% byte-identical before this).

    Expects: $checkinBase (string, no trailing slash) — where THIS page's
    confirm POST goes; $lookupUrl (string); $selfQrBase (string, no trailing
    slash) — the fixed prefix every credential's own QR encodes, regardless
    of which scanner page decodes it (Guest::checkInQrUrl()'s dashboard-route
    shape, or Ticket::publicUrl()'s /t shape). Used by the authenticated host
    scanner (scan.blade.php) and the no-login staff scanner link
    (public-scan.blade.php) for each kind. On the host scanner $selfQrBase
    and $checkinBase are the same value; on the staff-link scanner they
    differ — the QR is only ever used to recognize a token, never dialled
    directly, so the confirm POST always lands on this page's own endpoint.
    $checkInOpen / $checkInDateLabel come from Event::isCheckInOpen() — the
    camera stays off on any day that is not the event date.
--}}
@php
    $kind = $kind ?? 'guest';
    $checkInOpen = $checkInOpen ?? false;
    $checkInDateLabel = $checkInDateLabel ?? null;
    $checkInClosedCopy = 'Check-in is only available on the event date'
        .($checkInDateLabel ? ' ('.$checkInDateLabel.')' : '')
        .'.';
    $cameraHint = $kind === 'ticket' ? "Point the camera at a ticket's QR code." : "Point the camera at a guest's invitation QR code.";
    $lookupLabel = $kind === 'ticket' ? 'Search ticket by attendee name' : 'Search guest by name';
    $lookupPlaceholder = $kind === 'ticket' ? "Can't scan? Search attendee by name" : "Can't scan? Search guest by name";
@endphp
<div id="checkinScanner"
     class="ckin-root"
     data-checkin-kind="{{ $kind }}"
     data-checkin-base="{{ $checkinBase }}"
     data-self-qr-base="{{ $selfQrBase }}"
     data-lookup-url="{{ $lookupUrl }}"
     data-checkin-open="{{ $checkInOpen ? '1' : '0' }}">

    @if ($checkInOpen)
        <div class="ckin-camera-pane" data-ckin-camera>
            <video id="ckinVideo" class="ckin-video" playsinline muted></video>
            <canvas id="ckinCanvas" class="ckin-canvas-hidden"></canvas>
            <div class="ckin-frame" aria-hidden="true"></div>
            <p class="ckin-camera-hint" id="ckinCameraHint">{{ $cameraHint }}</p>
        </div>

        <div class="ckin-result-pane" data-ckin-result-pane hidden>
            <div class="ckin-result" id="ckinResult" aria-live="polite"></div>
            {{-- Filled by checkin-scanner.js from the same confirm() response — field
                 set depends on $kind (guest: email/phone/table/meal/RSVP note;
                 ticket: type/email/phone/order). Rows are added only for fields the
                 record actually has, and :empty collapses this to nothing between scans. --}}
            <dl class="ckin-result-details" id="ckinResultDetails" aria-live="polite"></dl>
            <button type="button" class="btn-primary" id="ckinScanAgain" hidden>
                <i class="fa-solid fa-camera" aria-hidden="true"></i>
                Scan again
            </button>
        </div>

        <div class="ckin-manual">
            <label class="evt-sr-only" for="ckinLookupInput">{{ $lookupLabel }}</label>
            <input id="ckinLookupInput" type="search" class="profile-input" placeholder="{{ $lookupPlaceholder }}" autocomplete="off">
            <ul class="ckin-lookup-results" id="ckinLookupResults"></ul>
        </div>
    @else
        <p class="ckin-closed">
            <i class="fa-solid fa-calendar-day" aria-hidden="true"></i>
            {{ $checkInClosedCopy }}
        </p>
    @endif
</div>
