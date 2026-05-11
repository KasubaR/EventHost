@php
    use Illuminate\Support\Str;
    $publicUrl = route('events.public', ['slug' => $event->slug]);
    $title = $event->name.' — '.config('app.name');
    $descRaw = trim(strip_tags((string) ($event->description ?? '')));
    $description = $descRaw !== ''
        ? Str::limit($descRaw, 200, preserveWords: true)
        : $event->name.' · '.$event->event_date->format('l, F j, Y');
    $imageUrl = $event->cover_image_url;
    if (! empty($invitation['media']['gallery'][0] ?? null)) {
        $p = $invitation['media']['gallery'][0];
        if (is_string($p) && $p !== '' && ! str_contains($p, '://')) {
            $imageUrl = asset('storage/'.$p);
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
