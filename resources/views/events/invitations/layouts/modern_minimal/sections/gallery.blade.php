@php
    $gallery = array_values(array_filter(array_map('strval', $invitation['media']['gallery'] ?? [])));
    $galleryId = 'mm-gallery-'.$event->getKey();
    $galleryMax = \App\Support\InvitationLayoutVariant::maxGalleryImages(
        \App\Support\InvitationLayoutVariant::MODERN_MINIMAL
    );
@endphp

@if (count($gallery) > 0)
    <section class="mm-section">
        <h2 class="mm-section-title">Moments</h2>
        <div class="mm-gallery">
            @foreach (array_slice($gallery, 0, $galleryMax) as $path)
                <div class="mm-gallery-item">
                    <a
                        href="{{ \App\Support\InvitationMediaUrl::resolve($path) }}"
                        class="glightbox mm-gallery-lightbox"
                        data-gallery="{{ $galleryId }}"
                        data-type="image"
                    >
                        <img src="{{ \App\Support\InvitationMediaUrl::resolve($path) }}" alt="" loading="lazy" width="800" height="600">
                    </a>
                </div>
            @endforeach
        </div>
    </section>
@endif
