@php
    use Illuminate\Support\Str;
    $publicUrl = route('events.public', ['slug' => $event->slug]);
    $title = $event->name.' | '.config('app.name');
    $descRaw = trim(strip_tags((string) ($event->description ?? '')));
    $description = $descRaw !== ''
        ? Str::limit($descRaw, 200, preserveWords: true)
        : $event->name.' · '.$event->event_date->format('l, F j, Y');

    // Which picture represents the event is decided in one place (InvitationShareImage::sourcePath) so this page and the job
    // that writes the preview JPEG cannot disagree. A WhatsApp or Facebook preview wants a JPEG at about 1200x630, so the JPEG is
    // used when it exists; until it does (an event not yet backfilled) the stored image is used, exactly as before.
    $shareSource = \App\Support\InvitationShareImage::sourcePath($event, $invitation['media'] ?? []);
    $shareJpeg = \App\Support\InvitationShareImage::existingFor($event, $shareSource);

    if ($shareJpeg !== null) {
        $imageUrl = asset('storage/'.$shareJpeg);
    } elseif ($shareSource !== null) {
        $imageUrl = asset('storage/'.$shareSource);
    } else {
        $imageUrl = $event->cover_image_url;
    }
@endphp
<link rel="canonical" href="{{ $publicUrl }}">
<meta name="description" content="{{ $description }}">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $event->name }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $publicUrl }}">
<meta property="og:image" content="{{ $imageUrl }}">
@if ($shareJpeg !== null)
<meta property="og:image:type" content="image/jpeg">
<meta property="og:image:width" content="{{ \App\Support\InvitationShareImage::WIDTH }}">
<meta property="og:image:height" content="{{ \App\Support\InvitationShareImage::HEIGHT }}">
@endif
<meta property="og:image:alt" content="{{ \App\Support\InvitationShareImage::altText($event) }}">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $event->name }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $imageUrl }}">
<meta name="twitter:image:alt" content="{{ \App\Support\InvitationShareImage::altText($event) }}">
