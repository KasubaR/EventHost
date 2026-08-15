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

    /*
    |--------------------------------------------------------------------------
    | Staged media upload rate limit (per authenticated user, per minute)
    |--------------------------------------------------------------------------
    |
    | Images upload as they are picked, one request each, so this is per file
    | rather than per save.
    |
    */

    'media_uploads_per_minute' => (int) env('INVITATION_MEDIA_RATE_LIMIT', 60),

    /*
    |--------------------------------------------------------------------------
    | Staged media time-to-live (minutes)
    |--------------------------------------------------------------------------
    |
    | A staged row whose form was never saved is abandoned after this long, and
    | invitation:prune-orphaned-files deletes both the row and its file. Until it
    | expires, the staged path counts as referenced, so the same command's orphan
    | sweep leaves it alone.
    |
    */

    'staged_media_ttl_minutes' => (int) env('INVITATION_STAGED_MEDIA_TTL', 1440),

];
