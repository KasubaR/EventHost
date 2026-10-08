@extends('layouts.site')
@php $guestPage = true; @endphp

@push('head')
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
@endpush

@section('title', 'Request seats | '.$event->name)

@section('content')
    @php $open = $state === \App\Services\GroupRsvpResolver::OPEN; @endphp
    <article class="rsvp-page">
        <div class="rsvp-card">
            <header class="rsvp-header">
                <p class="rsvp-event-badge"><i class="fa-solid fa-envelope" aria-hidden="true"></i> Invitation &middot; {{ $group->name }}</p>
                <h1 class="rsvp-title">{{ $event->name }}</h1>
                <ul class="rsvp-meta">
                    <li>
                        <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                        {{ $event->event_date->format('l, F j, Y') }}
                        @if ($event->event_time)
                            &middot; {{ \Illuminate\Support\Str::substr($event->event_time, 0, 5) }}
                        @endif
                    </li>
                    @if ($event->venue)
                        <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $event->venue }}</li>
                    @elseif (\App\Support\EventPlace::isUnknown($event))
                        <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ \App\Support\EventPlace::TO_BE_ANNOUNCED_LINE }}</li>
                    @endif
                </ul>
                <hr class="rsvp-divider">

                @if ($open)
                    <p class="rsvp-lead">
                        Seats for {{ $group->name }} are limited. Request yours below &mdash; the host confirms each request,
                        and we'll email you a personal pass once it is approved.
                    </p>
                    @if ($event->rsvp_deadline !== null)
                        <p class="rsvp-muted rsvp-cutoff"><i class="fa-solid fa-clock" aria-hidden="true"></i> RSVP closes {{ $event->rsvpDeadlineLabel() }}.</p>
                    @endif
                @elseif ($state === \App\Services\GroupRsvpResolver::FULL)
                    <div class="evt-rsvp-banner evt-rsvp-banner--closed rsvp-closed-banner">
                        <i class="fa-solid fa-users"></i>
                        All seats for {{ $group->name }} have been taken.
                    </div>
                @elseif ($state === \App\Services\GroupRsvpResolver::CLOSED)
                    <div class="evt-rsvp-banner evt-rsvp-banner--closed rsvp-closed-banner">
                        <i class="fa-solid fa-clock"></i>
                        This link is no longer taking requests.
                    </div>
                @else
                    <div class="evt-rsvp-banner evt-rsvp-banner--closed rsvp-closed-banner">
                        <i class="fa-solid fa-circle-info"></i>
                        This event is not available right now.
                    </div>
                @endif
            </header>

            @if ($open)
                <form method="post" action="{{ route('group-rsvp.store', ['token' => $group->rsvp_token]) }}" class="rsvp-form">
                    @csrf
                    @error('status')
                        <p class="rsvp-field-error">{{ $message }}</p>
                    @enderror
                    <div class="rsvp-field-group">
                        <label class="rsvp-field-label" for="grp_name">Full name</label>
                        <input id="grp_name" type="text" name="name" class="rsvp-input" required maxlength="191" value="{{ old('name') }}" autocomplete="name">
                        @error('name')<p class="rsvp-field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="rsvp-field-group">
                        <label class="rsvp-field-label" for="grp_email">Email</label>
                        <input id="grp_email" type="email" name="email" class="rsvp-input" required maxlength="191" value="{{ old('email') }}" autocomplete="email">
                        @error('email')<p class="rsvp-field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="rsvp-field-group">
                        <label class="rsvp-field-label" for="grp_phone">Phone</label>
                        <input id="grp_phone" type="tel" name="phone" class="rsvp-input" required maxlength="50" value="{{ old('phone') }}" autocomplete="tel" placeholder="0971234567">
                        @error('phone')<p class="rsvp-field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="rsvp-field-group">
                        <label class="rsvp-field-label" for="grp_seats">Seats</label>
                        <select id="grp_seats" name="attendee_count" class="rsvp-select" required>
                            @for ($n = 1; $n <= $maxSeats; $n++)
                                <option value="{{ $n }}" @selected((int) old('attendee_count', 1) === $n)>
                                    {{ $n }} {{ $n === 1 ? 'seat (just me)' : 'seats (me plus one guest)' }}
                                </option>
                            @endfor
                        </select>
                        @error('attendee_count')<p class="rsvp-field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="rsvp-field-group">
                        <label class="rsvp-field-label" for="grp_message">Message <span class="rsvp-optional">optional</span></label>
                        <textarea id="grp_message" name="message" class="rsvp-input" rows="3" maxlength="1000">{{ old('message') }}</textarea>
                    </div>
                    <button type="submit" class="btn-primary rsvp-submit">
                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Request my seat
                    </button>
                </form>
            @endif

            @include('rsvp.partials.host-contact', ['event' => $event])
        </div>
    </article>
@endsection

@push('scripts')
    <script src="{{ asset('js/rsvp-form.js') }}" defer></script>
@endpush
