@php
    $galleryMax = \App\Support\InvitationLayoutVariant::maxGalleryImages(
        \App\Support\InvitationLayoutVariant::WEDDING_MIDNIGHT_GOLD
    );
    $gallery = array_slice(array_values(array_filter(array_map('strval', $invitation['media']['gallery'] ?? []))), 0, $galleryMax);
    $galleryId = 'mg-gallery-'.$event->getKey();
@endphp

@if (count($gallery) > 0)
    <section class="mg-section mg-section--alt" id="gallery">
        <h2 class="mg-title">Gallery</h2>
        <div class="mg-gallery mg-gallery--count-{{ count($gallery) }}">
            @foreach ($gallery as $path)
                <a
                    href="{{ \App\Support\InvitationMediaUrl::resolve($path) }}"
                    class="glightbox mg-gallery-item"
                    data-gallery="{{ $galleryId }}"
                    data-type="image"
                >
                    <img src="{{ \App\Support\InvitationMediaUrl::resolve($path) }}" alt="{{ \App\Support\InvitationMediaUrl::galleryAlt($path, $invitation['media']['gallery'], $event->name) }}"{!! \App\Support\InvitationMediaUrl::responsiveAttributes($path) !!} loading="lazy" width="800" height="800">
                </a>
            @endforeach
        </div>
    </section>
@endif
