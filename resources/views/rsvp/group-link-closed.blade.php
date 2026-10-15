@extends('layouts.site')
@php $guestPage = true; @endphp

{{-- plans/guest-groups-gaps.md Phase 2: a group's shared link the host deleted or turned off. Names the event, never the group or
     anyone in it, and shows the host's number when there is one. --}}
@section('title', 'This link was closed | '.config('app.name'))

@push('head')
    <link rel="stylesheet" href="{{ asset('css/errors.css') }}">
@endpush

@section('content')

<section class="err-hero" aria-labelledby="err-title">
    <div class="err-hero-inner">
        <p class="err-code" aria-hidden="true">410</p>
        <span class="err-eyebrow">Link closed</span>
        <h1 id="err-title">This RSVP link was closed</h1>
        <p class="err-lead">
            @if ($event)
                The host of <strong>{{ $event->name }}</strong> has closed this shared link, so it no longer takes requests.
            @else
                The host has closed this shared link, so it no longer takes requests.
            @endif
            If you already sent a request, the host still has it. If you need a seat, please get in touch with the host.
        </p>
        <div class="err-lead">
            @include('rsvp.partials.host-contact', ['event' => $event])
        </div>
        <div class="err-ctas">
            <a href="{{ route('home') }}" class="btn-hero-secondary">Home</a>
        </div>
    </div>
</section>

@endsection
