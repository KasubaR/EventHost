@php
    $gallery = array_values(array_filter(array_map('strval', $invitation['media']['gallery'] ?? [])));
    $galleryId = 'wi2-gallery-'.$event->getKey();
    $row1 = array_slice($gallery, 0, 3);
    $row2 = array_slice($gallery, 3, 3);
@endphp

@if (count($gallery) > 0)
    <div class="wi2-gallery-section wi2-reveal" data-wi2-reveal>
        @if (count($row1) > 0)
            <div class="wi2-gallery-row wi2-gallery-row--1">
                @foreach ($row1 as $path)
                    <div class="wi2-gallery-cell">
                        <a
                            href="{{ asset('storage/'.$path) }}"
                            class="glightbox wi2-gallery-lightbox"
                            data-gallery="{{ $galleryId }}"
                            data-type="image"
                        >
                            <img src="{{ asset('storage/'.$path) }}" alt="" loading="lazy" width="800" height="500">
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
        @if (count($row2) > 0)
            <div class="wi2-gallery-row wi2-gallery-row--2">
                @foreach ($row2 as $path)
                    <div class="wi2-gallery-cell">
                        <a
                            href="{{ asset('storage/'.$path) }}"
                            class="glightbox wi2-gallery-lightbox"
                            data-gallery="{{ $galleryId }}"
                            data-type="image"
                        >
                            <img src="{{ asset('storage/'.$path) }}" alt="" loading="lazy" width="800" height="500">
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
