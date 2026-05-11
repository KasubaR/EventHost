@if (! empty($invitation['media']['gallery']))
    @php
        $galleryId = 'evt-gallery-'.$event->getKey();
    @endphp
    <div class="evt-inv-gallery" data-inv-gallery>
        <h2 class="evt-inv-gallery-title">Gallery</h2>
        <div class="swiper evt-inv-gallery-swiper">
            <div class="swiper-wrapper">
                @foreach ($invitation['media']['gallery'] as $path)
                    <div class="swiper-slide">
                        <figure class="evt-inv-gallery-slide">
                            <a
                                href="{{ asset('storage/'.$path) }}"
                                class="glightbox evt-inv-gallery-lightbox"
                                data-gallery="{{ $galleryId }}"
                                data-type="image"
                            >
                                <img
                                    src="{{ asset('storage/'.$path) }}"
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                    width="800"
                                    height="600"
                                    class="evt-inv-gallery-slide-img"
                                >
                            </a>
                        </figure>
                    </div>
                @endforeach
            </div>
            <div class="swiper-pagination evt-inv-gallery-pagination"></div>
            <button type="button" class="swiper-button-prev evt-inv-gallery-prev" aria-label="Previous slide"></button>
            <button type="button" class="swiper-button-next evt-inv-gallery-next" aria-label="Next slide"></button>
        </div>
    </div>
@endif
