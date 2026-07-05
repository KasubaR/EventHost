@extends('layouts.site')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/rsvp-public.css') }}">
@endpush

@php
    $meta = session('thanks_meta', []);
@endphp

@section('title', 'Thank you | '.config('app.name'))

@section('content')
    <article class="rsvp-page evt-public-inner rsvp-thanks">
        <div class="evt-rsvp-banner evt-rsvp-banner--open rsvp-thanks-banner">
            <i class="fa-solid fa-circle-check"></i>
            Thank you{{ ! empty($meta['guest_name']) ? ', '.$meta['guest_name'] : '' }}!
        </div>
        @if (! empty($meta['event_name']))
            <p class="rsvp-lead">Your response for <strong>{{ $meta['event_name'] }}</strong> has been recorded.</p>
        @else
            <p class="rsvp-lead">Your response has been recorded.</p>
        @endif
        <p class="rsvp-muted">You can close this page. If you need to change your RSVP, use the same link you opened before.</p>
    </article>
@endsection
