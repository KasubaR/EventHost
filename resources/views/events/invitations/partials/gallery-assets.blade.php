{{-- The slider and lightbox libraries, served from our own server (public/vendor, no third-party host between a guest and
     the page) and only on pages whose invitation has gallery photos. Include BEFORE the page's own @push('head') and
     before the @push('scripts') that loads invitation-public.js: the libraries must run first. Pages with no gallery
     load neither. plans/invitation-page-resilience.md Phase 3. --}}
@if (! empty($invitation['media']['gallery']))
    {{-- The ?v= is the library version (public/vendor/README.md). Bump it with the files so a long cache lifetime on
         /vendor can never serve a stale copy. --}}
    @php
        $swiperV = '11.2.10';
        $glightboxV = '3.3.1';
    @endphp
    @push('head')
        <link rel="stylesheet" href="{{ asset('vendor/swiper/swiper-bundle.min.css') }}?v={{ $swiperV }}">
        <link rel="stylesheet" href="{{ asset('vendor/glightbox/glightbox.min.css') }}?v={{ $glightboxV }}">
    @endpush

    @push('scripts')
        <script src="{{ asset('vendor/swiper/swiper-bundle.min.js') }}?v={{ $swiperV }}" defer></script>
        <script src="{{ asset('vendor/glightbox/glightbox.min.js') }}?v={{ $glightboxV }}" defer></script>
    @endpush
@endif
