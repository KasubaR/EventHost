@php
    $gallery = array_values(array_filter(array_map('strval', $invitation['media']['gallery'] ?? [])));
    $gridClasses = ['wi-gallery-item--g1', 'wi-gallery-item--g2', 'wi-gallery-item--g3', 'wi-gallery-item--g4', 'wi-gallery-item--g5', 'wi-gallery-item--g6'];
    $galleryId = 'wi-gallery-'.$event->getKey();
@endphp

@if (count($gallery) > 0)
    <section class="wi-gallery-section wi-reveal" data-wi-reveal>
        <p class="wi-section-tag">Our Moments</p>
        <h2 class="wi-section-title">A <em>glimpse</em> of us</h2>
        <div class="wi-gallery-grid">
            @foreach (array_slice($gallery, 0, 6) as $idx => $path)
                <div class="wi-gallery-item {{ $gridClasses[$idx] ?? '' }}">
                    <a
                        href="{{ asset('storage/'.$path) }}"
                        class="glightbox"
                        data-gallery="{{ $galleryId }}"
                        data-type="image"
                    >
                        <img
                            src="{{ asset('storage/'.$path) }}"
                            alt=""
                            loading="lazy"
                            decoding="async"
                            width="600"
                            height="400"
                        >
                    </a>
                </div>
            @endforeach
        </div>
    </section>
@endif
