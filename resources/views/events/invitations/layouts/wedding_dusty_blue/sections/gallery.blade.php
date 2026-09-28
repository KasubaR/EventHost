@php
    $galleryMax = \App\Support\InvitationLayoutVariant::maxGalleryImages(
        \App\Support\InvitationLayoutVariant::WEDDING_DUSTY_BLUE
    );
    $gallery = array_slice(array_values(array_filter(array_map('strval', $invitation['media']['gallery'] ?? []))), 0, $galleryMax);
    $galleryId = 'db-gallery-'.$event->getKey();
@endphp

@if (count($gallery) > 0)
    <section class="db-section" id="gallery">
        <h2 class="db-title">Gallery</h2>
        @include('events.invitations.layouts.wedding_dusty_blue.partials.divider')
        <div class="db-gallery db-gallery--count-{{ count($gallery) }}">
            @foreach ($gallery as $path)
                <a
                    href="{{ \App\Support\InvitationMediaUrl::resolve($path) }}"
                    class="glightbox db-print"
                    data-gallery="{{ $galleryId }}"
                    data-type="image"
                >
                    <img src="{{ \App\Support\InvitationMediaUrl::resolve($path) }}" alt="" loading="lazy" width="800" height="800">
                </a>
            @endforeach
        </div>
    </section>
@endif
