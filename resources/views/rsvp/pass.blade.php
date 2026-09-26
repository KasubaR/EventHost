{{--
    The guest's invitation pass on its own page (twin of tickets/show.blade.php).
    Bookmarkable, token-guarded, noindex. See RsvpController::pass() and
    plans/invitation-pass-card.md.
--}}
@extends('layouts.site')
@php $hideSiteHeader = true; $hideSiteFooter = true; @endphp

@push('head')
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
@endpush

@section('title', 'Your pass | '.$event->name)

@section('content')
    <div class="gpass-page">
        @include('rsvp.partials.pass-card', ['card' => $card, 'guest' => $guest])

        <div class="gpass-actions">
            <a href="{{ route('rsvp.token.pass-download', ['token' => $guest->invitation_token]) }}" class="rsvp-pass-download">
                <i class="fa-solid fa-file-pdf" aria-hidden="true"></i>
                Download PDF
            </a>
            <a href="{{ route('rsvp.token.pass-image', ['token' => $guest->invitation_token, 'download' => 1]) }}" class="rsvp-pass-download">
                <i class="fa-solid fa-image" aria-hidden="true"></i>
                Save as image
            </a>
        </div>

        <a href="{{ route('rsvp.token.show', ['token' => $guest->invitation_token]) }}" class="gpass-page-back">
            View invitation or change your RSVP
        </a>
    </div>
@endsection
