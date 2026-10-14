@php
    $galleryMax = \App\Support\InvitationLayoutVariant::maxGalleryImages(
        \App\Support\InvitationLayoutVariant::WEDDING_ROYAL_BLUE
    );
    $gallery = array_slice(array_values(array_filter(array_map('strval', $invitation['media']['gallery'] ?? []))), 0, $galleryMax);
    $galleryId = 'rb-gallery-'.$event->getKey();
@endphp

@if (count($gallery) > 0)
    <section class="rb-section" id="gallery">
        <div class="rb-wrap">
            <h2 class="rb-title">Captured Moments</h2>
            @include('events.invitations.layouts.wedding_royal_blue.partials.ornament')
            <div class="rb-gallery rb-gallery--count-{{ count($gallery) }}">
                @foreach ($gallery as $path)
                    <a
                        href="{{ \App\Support\InvitationMediaUrl::resolve($path) }}"
                        class="glightbox rb-gallery-item"
                        data-gallery="{{ $galleryId }}"
                        data-type="image"
                    >
                        <img src="{{ \App\Support\InvitationMediaUrl::resolve($path) }}" alt="{{ \App\Support\InvitationMediaUrl::galleryAlt($path, $invitation['media']['gallery'], $event->name) }}"{!! \App\Support\InvitationMediaUrl::responsiveAttributes($path) !!} loading="lazy" width="800" height="600">
                    </a>
                @endforeach
            </div>
        </div>
    </section>
@endif
