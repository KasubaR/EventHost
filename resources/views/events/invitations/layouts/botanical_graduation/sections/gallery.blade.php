@if (! empty($invitation['media']['gallery']))
    <div class="gallery-section evt-bg-gallery-section">
        <p class="section-eyebrow">Memories</p>
        <h2 class="section-title evt-bg-section-title-h2">Gallery</h2>

        @php
            $galleryPaths = array_slice(
                $invitation['media']['gallery'],
                0,
                \App\Support\InvitationLayoutVariant::maxGalleryImages(\App\Support\InvitationLayoutVariant::BOTANICAL_GRADUATION)
            );
            $gmClasses = ['gm-1', 'gm-2', 'gm-3', 'gm-4', 'gm-5'];
            $galleryId = 'bg-gallery-'.$event->getKey();
        @endphp
        <div class="gallery-mosaic evt-bg-gallery-mosaic">
            @foreach ($galleryPaths as $index => $path)
                <figure class="gm-tile {{ $gmClasses[$index] ?? 'gm-5' }} evt-bg-gm-figure">
                    <a href="{{ \App\Support\InvitationMediaUrl::resolve($path) }}"
                       class="glightbox evt-bg-gallery-lightbox"
                       data-gallery="{{ $galleryId }}"
                       data-type="image"
                       aria-label="Open photo {{ $index + 1 }}">
                        <img src="{{ \App\Support\InvitationMediaUrl::resolve($path) }}" alt="" loading="lazy" decoding="async" width="800" height="600">
                    </a>
                </figure>
            @endforeach
        </div>

    </div>

    <div class="botanical-divider evt-bg-mini-divider" aria-hidden="true">
        <svg width="80" height="40" viewBox="0 0 80 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <ellipse cx="20" cy="20" rx="16" ry="7" fill="#8FA98C" transform="rotate(-30 20 20)"/>
            <ellipse cx="60" cy="20" rx="16" ry="7" fill="#8FA98C" transform="rotate(30 60 20)"/>
            <circle cx="40" cy="20" r="6" fill="#C4847A"/>
        </svg>
    </div>
@endif
