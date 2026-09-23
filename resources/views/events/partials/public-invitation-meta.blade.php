@php
    use Illuminate\Support\Str;
    $publicUrl = route('events.public', ['slug' => $event->slug]);
    $title = $event->name.' — '.config('app.name');
    $descRaw = trim(strip_tags((string) ($event->description ?? '')));
    $description = $descRaw !== ''
        ? Str::limit($descRaw, 200, preserveWords: true)
        : $event->name.' · '.$event->event_date->format('l, F j, Y');

    $storageImage = function (mixed $path): ?string {
        if (! is_string($path) || $path === '' || str_contains($path, '://')) {
            return null;
        }

        return asset('storage/'.$path);
    };

    $imageUrl = $event->cover_image_url;
    if (filled($event->cover_image)) {
        // Host set a cover — gallery still wins for share cards when present
        // (same as before botanical's portrait fallback).
        $galleryUrl = $storageImage($invitation['media']['gallery'][0] ?? null);
        if ($galleryUrl !== null) {
            $imageUrl = $galleryUrl;
        }
    } else {
        // No cover (e.g. botanical hero portraits): prefer first portrait, then
        // gallery, then the platform default from cover_image_url. Never write
        // these paths into events.cover_image.
        $couplePaths = array_values(array_filter(array_map('strval', $invitation['media']['couple_photos'] ?? [])));
        $heroPortrait = $invitation['media']['hero_portrait'] ?? null;
        $portraitUrl = $storageImage($couplePaths[0] ?? null)
            ?? $storageImage(is_string($heroPortrait) ? $heroPortrait : null);
        if ($portraitUrl !== null) {
            $imageUrl = $portraitUrl;
        } else {
            $galleryUrl = $storageImage($invitation['media']['gallery'][0] ?? null);
            if ($galleryUrl !== null) {
                $imageUrl = $galleryUrl;
            }
        }
    }
@endphp
<link rel="canonical" href="{{ $publicUrl }}">
<meta name="description" content="{{ $description }}">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $event->name }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $publicUrl }}">
<meta property="og:image" content="{{ $imageUrl }}">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $event->name }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $imageUrl }}">
