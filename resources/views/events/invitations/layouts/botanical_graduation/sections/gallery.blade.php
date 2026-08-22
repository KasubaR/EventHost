@if (! empty($invitation['media']['gallery']))
    <div class="gallery-section evt-bg-gallery-section">
        <p class="section-eyebrow">Memories</p>
        <h2 class="section-title evt-bg-section-title-h2">Gallery</h2>

        @php
            $galleryPaths = array_slice($invitation['media']['gallery'], 0, 5);
            $gmClasses = ['gm-1', 'gm-2', 'gm-3', 'gm-4', 'gm-5'];
        @endphp
        <div class="gallery-mosaic evt-bg-gallery-mosaic">
            @foreach ($galleryPaths as $index => $path)
                <figure class="gm-tile {{ $gmClasses[$index] ?? 'gm-5' }} evt-bg-gm-figure">
                    <img src="{{ \App\Support\InvitationMediaUrl::resolve($path) }}" alt="" loading="lazy" decoding="async" width="800" height="600">
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
