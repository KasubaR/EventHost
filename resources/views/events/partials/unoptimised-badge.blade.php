{{-- A photo ProcessInvitationDesignImageJob gave up converting (media.unoptimised). Guests still
     see it, as the full-size original. --}}
@if (in_array($path, $unoptimised, true))
    <span class="evt-design-media-badge" title="We could not optimise this photo, so guests download the original file. Remove it and upload a JPG or PNG to fix it.">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Not optimised
    </span>
@endif
