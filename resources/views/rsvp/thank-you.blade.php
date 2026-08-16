{{--
    Two data sources land here, both normalised into the same $event/$guest/$rsvp
    shape by RsvpController::confirmationViewData():
      - thanksByToken() — re-queried fresh every load (refreshable, bookmarkable)
      - thanks() — a one-shot session flash for open (no-token) RSVPs, since
        there's no persistent guest identifier safe to put in a URL for them
    $event/$guest/$rsvp are all null only when someone lands on /rsvp/thanks
    directly (flash already consumed, or never existed) — see the @else branch.
--}}
@extends('layouts.site')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
@endpush

@section('title', 'Thank you | '.config('app.name'))

@section('content')
    <article class="rsvp-page evt-public-inner rsvp-thanks">
        @if ($event && $guest && $rsvp)
            <div class="evt-rsvp-banner evt-rsvp-banner--open rsvp-thanks-banner">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                Thank you, {{ $guest->name }}!
            </div>
            <p class="rsvp-lead">Your RSVP has been received.</p>

            <div class="rsvp-card rsvp-thanks-card">
                <h2 class="rsvp-thanks-event">{{ $event->name }}</h2>

                @php $locationString = \App\Support\EventCalendarLinks::locationString($event); @endphp
                @if ($event->event_date || $locationString !== '')
                    <ul class="rsvp-meta">
                        @if ($event->event_date)
                            <li><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> {{ $event->event_date->format('j F Y') }}</li>
                        @endif
                        @if ($locationString !== '')
                            <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $locationString }}</li>
                        @endif
                    </ul>
                @endif

                <hr class="rsvp-divider">

                <p class="rsvp-thanks-response">
                    Your response:
                    <span class="rsvp-thanks-status rsvp-thanks-status--{{ $rsvp->status->value }}">{{ $rsvp->status->attendanceLabel() }}</span>
                </p>
                @if ($rsvp->status->countsTowardGuestLimit())
                    <p class="rsvp-thanks-count">Guests: {{ $rsvp->attendee_count }}</p>
                @endif

                @if ($rsvp->status === \App\Enums\RsvpStatus::Accepted)
                    <p class="rsvp-thanks-footer">We'll see you there! <i class="fa-solid fa-heart" aria-hidden="true"></i></p>
                @endif
            </div>

            @include('rsvp.partials.entry-pass', ['guest' => $guest, 'showEntryPass' => $showEntryPass ?? false])

            <div class="rsvp-thanks-actions">
                @if ($viewInvitationUrl)
                    <a href="{{ $viewInvitationUrl }}" class="rsvp-thanks-btn">
                        <i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i> View Invitation
                    </a>
                @endif

                @if ($hasCalendarWindow)
                    <div class="rsvp-thanks-btn rsvp-thanks-btn--menu" data-rsvp-calendar-menu>
                        <button type="button" class="rsvp-thanks-btn-trigger" data-rsvp-calendar-trigger aria-expanded="false">
                            <i class="fa-solid fa-calendar-plus" aria-hidden="true"></i> Add to Calendar
                        </button>
                        <div class="rsvp-thanks-menu" data-rsvp-calendar-panel hidden>
                            <a href="{{ \App\Support\EventCalendarLinks::googleCalendarUrl($event) }}" target="_blank" rel="noopener noreferrer">Google Calendar</a>
                            <a href="{{ \App\Support\EventCalendarLinks::outlookCalendarUrl($event) }}" target="_blank" rel="noopener noreferrer">Outlook</a>
                            <a href="{{ route('events.public.ics', ['slug' => $event->slug]) }}">Apple / .ics file</a>
                        </div>
                    </div>
                @endif

                @if ($changeRsvpUrl)
                    <a href="{{ $changeRsvpUrl }}" class="rsvp-thanks-btn">
                        <i class="fa-solid fa-pen" aria-hidden="true"></i> Change RSVP
                    </a>
                @endif

                @if ($shareUrl)
                    <button type="button" class="rsvp-thanks-btn" data-rsvp-share data-share-url="{{ $shareUrl }}" data-share-title="{{ $event->name }}">
                        <i class="fa-solid fa-share-nodes" aria-hidden="true"></i> <span data-rsvp-share-label>Share</span>
                    </button>
                @endif
            </div>

            @unless ($refreshable)
                <p class="rsvp-muted">Reloading this page won't show your details again — bookmark the RSVP link from your invite instead if you want to come back.</p>
            @endunless
        @else
            <div class="evt-rsvp-banner evt-rsvp-banner--open rsvp-thanks-banner">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                Thank you!
            </div>
            <p class="rsvp-lead">Your response has been recorded.</p>
            <p class="rsvp-muted">You can close this page. If you need to change your RSVP, use the same link you opened before.</p>
        @endif
    </article>
@endsection

@push('scripts')
    <script src="{{ asset('js/rsvp-thanks.js') }}" defer></script>
@endpush
