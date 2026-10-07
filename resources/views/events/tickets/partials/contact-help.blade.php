{{--
    "Contact & Help" card on the public ticket page. Two blocks: the organizer (about the event
    itself: programme, venue, the day) when the host chose to show their details, and platform
    support (tickets, payments, refunds) always. Both phone links go through a digits-only href so
    "0977 123 456" still dials. Included by events/tickets/partials/landing-content.blade.php, so the
    host preview shows it too.
--}}
@php
    $site = config('app.name');
    $showOrganizer = $event->showsOrganizerOnPublicPage();
    $supportPhone = trim((string) config('events.support.phone'));
    $supportEmail = trim((string) config('mail.support_address'));
    $telHref = fn (string $number): string => 'tel:'.preg_replace('/[^0-9+]/', '', $number);
@endphp

@if ($showOrganizer || $supportPhone !== '' || $supportEmail !== '')
    <section class="tkc-card tev-contact" id="contact">
        <h2 class="tev-section-title">Contact &amp; Help</h2>
        <p class="tev-contact-lead">
            <strong>Questions about this event?</strong>
            @if ($showOrganizer)
                Ask the organizer about the event itself. For tickets, payments or your order, {{ $site }} support is here to help.
            @else
                For tickets, payments or your order, {{ $site }} support is here to help.
            @endif
        </p>

        <div class="tev-contact-grid">
            @if ($showOrganizer)
                <div class="tev-contact-block">
                    <p class="tev-contact-kicker">The organizer</p>
                    <p class="tev-contact-name">{{ $event->organizer_name }}</p>
                    <p class="tev-contact-note">The programme, the venue and the day itself.</p>
                    <ul class="tev-contact-list">
                        <li>
                            <i class="fa-solid fa-phone" aria-hidden="true"></i>
                            <a href="{{ $telHref($event->organizer_phone) }}">{{ $event->organizer_phone }}</a>
                        </li>
                        <li>
                            <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                            <a href="mailto:{{ $event->organizer_email }}">{{ $event->organizer_email }}</a>
                        </li>
                    </ul>
                </div>
            @endif

            @if ($supportPhone !== '' || $supportEmail !== '')
                <div class="tev-contact-block">
                    <p class="tev-contact-kicker">{{ $site }} support</p>
                    <p class="tev-contact-name">Need a hand?</p>
                    <p class="tev-contact-note">Tickets, payments and refunds.</p>
                    <ul class="tev-contact-list">
                        @if ($supportPhone !== '')
                            <li>
                                <i class="fa-solid fa-phone" aria-hidden="true"></i>
                                <a href="{{ $telHref($supportPhone) }}">{{ $supportPhone }}</a>
                            </li>
                        @endif
                        @if ($supportEmail !== '')
                            <li>
                                <i class="fa-regular fa-envelope" aria-hidden="true"></i>
                                <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                            </li>
                        @endif
                    </ul>
                </div>
            @endif
        </div>
    </section>
@endif
