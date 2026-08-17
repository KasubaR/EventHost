{{--
    The one fixed public template every ticketed event renders — there is no
    layout to choose (see EventChooseTemplateController's guard). Shared by
    events/tickets/landing.blade.php (the real /e/{slug} page) and
    events/preview.blade.php (host-only preview, isPreview = true), mirroring
    how events.invitations.renderer is shared by events/public.blade.php and
    events/preview.blade.php for invitation events.

    Structure mirrors the reference mock (preview.html): a full hero with the
    title/meta overlaid on the EventHost-set hero image (stored as cover_image),
    then a two-column layout — About / Tickets / Location on the left, a sticky
    buy-box on the right. Card chrome and buyer-facing text (.tkc-*) are reused
    from ticket-checkout.css so this page and the buy flow feel like one product;
    the hero/grid pieces are ticket-event-public.css's own (.tev-*) since nothing
    else on the site overlays text on an image like this.
--}}
@php
    $typeLabels = [
        'wedding' => 'Wedding',
        'birthday' => 'Birthday',
        'graduation' => 'Graduation',
        'corporate' => 'Corporate Event',
        'baby_shower' => 'Baby Shower',
        'funeral' => 'Memorial',
        'church' => 'Church Event',
    ];

    $ticketTypes = $event->ticketTypes;

    $prices = $ticketTypes->pluck('price')->map(fn ($p) => (float) $p);
    $priceHint = $prices->isEmpty()
        ? null
        : ($prices->unique()->count() === 1
            ? 'K'.number_format($prices->first(), 2)
            : 'From K'.number_format($prices->min(), 2));

    $calendarWindow = \App\Support\EventCalendarLinks::window($event);
    $googleCal = $calendarWindow ? \App\Support\EventCalendarLinks::googleCalendarUrl($event) : null;
    $outlookCal = $calendarWindow ? \App\Support\EventCalendarLinks::outlookCalendarUrl($event) : null;
    $icsHref = $calendarWindow && filled($event->slug ?? null)
        ? route('events.public.ics', ['slug' => $event->slug])
        : null;

    $shareUrl = route('events.public', $event->slug);
    $waShareUrl = 'https://wa.me/?text='.rawurlencode('Check out '.$event->name.': '.$shareUrl);
@endphp

@if (! empty($isPreview))
    <div class="evt-session-banner evt-session-banner--info">
        <i class="fa-solid fa-eye" aria-hidden="true"></i>
        Preview — this is exactly how your ticket page looks to buyers.
    </div>
@endif

<div class="tev-page">
    <div class="tev-hero @if (! $event->cover_image) tev-hero--fallback @endif">
        @if ($event->cover_image)
            <img src="{{ $event->cover_image_url }}" alt="" class="tev-hero-img">
        @endif
        <div class="tev-hero-scrim" aria-hidden="true"></div>
        <div class="tev-hero-inner">
            <div class="tev-hero-content">
                <span class="tev-hero-type">{{ $typeLabels[$event->event_type] ?? $event->event_type }} &middot; Tickets</span>
                <h1 class="tev-hero-title">{{ $event->name }}</h1>
                @if ($event->description)
                    <p class="tev-hero-lead">{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags((string) $event->description))), 160) }}</p>
                @endif
                <ul class="tev-hero-meta">
                    <li>
                        <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                        {{ $event->event_date->format('l, F j, Y') }}
                    </li>
                    @if ($event->event_time)
                        <li>
                            <i class="fa-regular fa-clock" aria-hidden="true"></i>
                            {{ \Illuminate\Support\Str::substr($event->event_time, 0, 5) }}
                        </li>
                    @endif
                    @if ($event->venue)
                        <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $event->venue }}</li>
                    @endif
                </ul>
            </div>
        </div>
    </div>

    <div class="tev-wrap">
        <div class="tev-layout">
        <div class="tev-main">
            @if ($event->description)
                <section class="tkc-card tev-about">
                    <h2 class="tev-section-title">About this event</h2>
                    <p class="evt-desc-body">{{ $event->description }}</p>
                </section>
            @endif

            <section class="tkc-card tev-tickets" id="tickets">
                <h2 class="tev-section-title">Tickets</h2>

                @if ($ticketTypes->isEmpty())
                    <p class="tkc-muted">Tickets are not available for this event right now.</p>
                @else
                    <div class="tev-ticket-list">
                        @foreach ($ticketTypes as $ticketType)
                            @php
                                $available = $ticketType->availableQuantity();
                                $purchasable = $ticketType->isPurchasable();
                            @endphp
                            <div class="tev-ticket-row {{ ! $purchasable ? 'is-disabled' : '' }}">
                                <div>
                                    <div class="tkc-type-name">{{ $ticketType->name }}</div>
                                    @if ($ticketType->description)
                                        <p class="tkc-type-desc">{{ $ticketType->description }}</p>
                                    @endif
                                    @if (! $purchasable)
                                        <p class="tkc-type-note">{{ $available === 0 ? 'Sold out' : 'Sales closed' }}</p>
                                    @elseif ($available !== null && $available <= 10)
                                        <p class="tkc-type-note">Only {{ $available }} left</p>
                                    @endif
                                </div>
                                <div class="tkc-type-price">K{{ number_format((float) $ticketType->price, 2) }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            @if ($event->venue || $event->location_name || ($event->latitude !== null && $event->longitude !== null))
                <section class="tkc-card tev-location">
                    <h2 class="tev-section-title">Location</h2>
                    <ul class="evt-detail-list">
                        @if ($event->venue)
                            <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $event->venue }}</li>
                        @endif
                        @if ($event->location_name)
                            <li><i class="fa-regular fa-map" aria-hidden="true"></i> {{ $event->location_name }}</li>
                        @endif
                        @if ($event->latitude !== null && $event->longitude !== null)
                            <li>
                                <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
                                <a href="https://www.google.com/maps?q={{ $event->latitude }},{{ $event->longitude }}" target="_blank" rel="noopener noreferrer">Open in Google Maps</a>
                            </li>
                        @endif
                    </ul>

                    @if ($calendarWindow && ($googleCal || $outlookCal || $icsHref))
                        <div class="tev-calendar-actions">
                            <p class="tev-calendar-actions-label">Add to calendar</p>
                            <ul class="tev-calendar-actions-list">
                                @if ($googleCal)
                                    <li>
                                        <a href="{{ $googleCal }}" class="tev-calendar-btn" target="_blank" rel="noopener noreferrer">
                                            <i class="fa-brands fa-google" aria-hidden="true"></i> Google
                                        </a>
                                    </li>
                                @endif
                                @if ($outlookCal)
                                    <li>
                                        <a href="{{ $outlookCal }}" class="tev-calendar-btn" target="_blank" rel="noopener noreferrer">
                                            <i class="fa-regular fa-calendar" aria-hidden="true"></i> Outlook
                                        </a>
                                    </li>
                                @endif
                                @if ($icsHref)
                                    <li>
                                        <a href="{{ $icsHref }}" class="tev-calendar-btn" download>
                                            <i class="fa-brands fa-apple" aria-hidden="true"></i> Apple / .ics
                                        </a>
                                    </li>
                                @endif
                            </ul>
                        </div>
                    @endif
                </section>
            @endif
        </div>

        <aside class="tev-side">
            <div class="tkc-card tev-buybox">
                <h2 class="tev-section-title">Tickets</h2>

                @if ($ticketTypes->isEmpty())
                    <p class="tkc-muted">Tickets are not available for this event right now.</p>
                @else
                    @if ($priceHint)
                        <p class="tev-price-hint">{{ $priceHint }}</p>
                    @endif

                    @if (! empty($isPreview))
                        <p class="tkc-muted">Ticket sales preview — publishing and activation unlock the live buy flow.</p>
                    @elseif ($event->ticketSalesAreApproved())
                        <p class="tkc-muted">Secure your spot — tickets are sold directly through EventHost.</p>
                        <a href="{{ route('events.public.tickets', $event->slug) }}" class="btn-primary tev-buy-btn">
                            <i class="fa-solid fa-ticket" aria-hidden="true"></i> Buy tickets
                        </a>
                        <p class="tev-secure-note">Secure payment through EventHost</p>
                    @else
                        <p class="tkc-muted">Ticket sales for this event haven't opened yet — check back soon.</p>
                    @endif
                @endif

                <div class="tev-share">
                    <button type="button" class="evt-btn-outline" data-tev-share data-share-url="{{ $shareUrl }}" data-share-title="{{ $event->name }}">
                        <i class="fa-solid fa-share-nodes" aria-hidden="true"></i> <span data-tev-share-label>Share</span>
                    </button>
                    <a href="{{ $waShareUrl }}" target="_blank" rel="noopener" class="evt-btn-outline">
                        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i> WhatsApp
                    </a>
                </div>
            </div>
        </aside>
        </div>
    </div>
</div>
