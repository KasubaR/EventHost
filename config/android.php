<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Android App Links (Digital Asset Links)
    |--------------------------------------------------------------------------
    |
    | Backs the /.well-known/assetlinks.json route (routes/web.php) that verifies this domain's
    | https:// links are allowed to open directly in the EventHostAndriodApp Android app instead
    | of a browser. Same env-driven pattern as config/social.php.
    |
    | sha256_fingerprints supports more than one certificate at once (comma-separated), so the
    | Android debug keystore's fingerprint (`./gradlew signingReport` in EventHostAndriodApp) can
    | be listed during development alongside the eventual Play App Signing release fingerprint —
    | add the release one later, no need to remove the debug one.
    */

    'package_name' => env('ANDROID_PACKAGE_NAME', 'com.sunconnecttechnologies.eventhost'),

    'sha256_fingerprints' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ANDROID_SHA256_FINGERPRINTS', ''))
    ))),

];
