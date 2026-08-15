{{--
    Shared camera-scan + manual-lookup widget.
    Expects: $checkinBase (string, no trailing slash) — where THIS page's confirm
    POST goes; $lookupUrl (string); $guestQrBase (string, no trailing slash) —
    the fixed .../events/{id}/checkin prefix every guest's own printed QR encodes
    (see Guest::checkInQrUrl()), regardless of which scanner page decodes it.
    Used by both the authenticated host scanner (scan.blade.php) and the
    no-login staff scanner link (public-scan.blade.php). On the host scanner
    $guestQrBase and $checkinBase are the same value; on the staff-link scanner
    they differ — the QR is only ever used to recognize a token, never dialled
    directly, so the confirm POST always lands on this page's own endpoint.
--}}
<div id="checkinScanner"
     class="ckin-root"
     data-checkin-base="{{ $checkinBase }}"
     data-guest-qr-base="{{ $guestQrBase }}"
     data-lookup-url="{{ $lookupUrl }}">

    <div class="ckin-camera-pane">
        <video id="ckinVideo" class="ckin-video" playsinline muted></video>
        <canvas id="ckinCanvas" class="ckin-canvas-hidden"></canvas>
        <div class="ckin-frame" aria-hidden="true"></div>
        <p class="ckin-camera-hint" id="ckinCameraHint">Point the camera at a guest's invitation QR code.</p>
    </div>

    <div class="ckin-result" id="ckinResult" aria-live="polite"></div>
    {{-- Filled by checkin-scanner.js from the same confirm() response — email, phone,
         table, meal preference, RSVP note. Rows are added only for fields the guest
         actually has, and :empty collapses this to nothing between scans. --}}
    <dl class="ckin-result-details" id="ckinResultDetails" aria-live="polite"></dl>

    <div class="ckin-manual">
        <label class="evt-sr-only" for="ckinLookupInput">Search guest by name</label>
        <input id="ckinLookupInput" type="search" class="profile-input" placeholder="Can't scan? Search guest by name" autocomplete="off">
        <ul class="ckin-lookup-results" id="ckinLookupResults"></ul>
    </div>
</div>
