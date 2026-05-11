<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Invitation gallery — total stored size cap
    |--------------------------------------------------------------------------
    |
    | Limit summed byte size of kept gallery files plus incoming uploads for one save.
    | Individual files are still capped by validation rules (e.g. 5 MB each, six slots).
    |
    */

    'gallery_max_total_bytes' => (int) env('INVITATION_GALLERY_MAX_TOTAL_BYTES', 15 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Invitation design PATCH rate limit (per authenticated user, per minute)
    |--------------------------------------------------------------------------
    */

    'design_updates_per_minute' => (int) env('INVITATION_DESIGN_RATE_LIMIT', 20),

];
