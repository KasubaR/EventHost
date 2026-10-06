{{-- One Google Fonts request for every family the invitation uses, instead of one stylesheet per family, with the font
     host preconnected. Fonts still swap in (display=swap), so text shows in a fallback face first.
     plans/invitation-page-resilience.md Phase 3. --}}
@php $families = array_values(array_filter((array) ($invitation['theme']['google_font_families'] ?? []))); @endphp
@if ($families !== [])
    @push('head')
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family={{ implode('&family=', $families) }}&display=swap" media="print" onload="this.media='all'">
        <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family={{ implode('&family=', $families) }}&display=swap"></noscript>
    @endpush
@endif
