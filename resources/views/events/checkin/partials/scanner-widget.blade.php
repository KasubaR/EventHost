{{--
    Shared camera-scan + manual-lookup widget.
    Expects: $checkinBase (string, no trailing slash), $lookupUrl (string).
    Used by both the authenticated host scanner (scan.blade.php) and the
    no-login staff scanner link (public-scan.blade.php).
--}}
<div id="checkinScanner"
     class="ckin-root"
     data-checkin-base="{{ $checkinBase }}"
     data-lookup-url="{{ $lookupUrl }}">

    <div class="ckin-camera-pane">
        <video id="ckinVideo" class="ckin-video" playsinline muted></video>
        <canvas id="ckinCanvas" class="ckin-canvas-hidden"></canvas>
        <div class="ckin-frame" aria-hidden="true"></div>
        <p class="ckin-camera-hint" id="ckinCameraHint">Point the camera at a guest's invitation QR code.</p>
    </div>

    <div class="ckin-result" id="ckinResult" aria-live="polite"></div>

    <div class="ckin-manual">
        <label class="evt-sr-only" for="ckinLookupInput">Search guest by name</label>
        <input id="ckinLookupInput" type="search" class="profile-input" placeholder="Can't scan? Search guest by name" autocomplete="off">
        <ul class="ckin-lookup-results" id="ckinLookupResults"></ul>
    </div>
</div>
