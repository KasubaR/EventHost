@extends('layouts.site')
@php $guestPage = true; @endphp

{{-- plans/guest-deletion-gaps.md Phase 4: a personal link whose guest the host removed. Says "removed" rather than
     "not found", names no guest, and shows the host's number when there is one. --}}
@section('title', 'Invitation removed | '.config('app.name'))

@push('head')
    <link rel="stylesheet" href="{{ asset('css/errors.css') }}">
@endpush

@section('content')

<section class="err-hero" aria-labelledby="err-title">
    <div class="err-hero-inner">
        <p class="err-code" aria-hidden="true">410</p>
        <span class="err-eyebrow">Invitation removed</span>
        <h1 id="err-title">This invitation is no longer active</h1>
        <p class="err-lead">
            @if ($event)
                The host of <strong>{{ $event->name }}</strong> has removed this invitation from the guest list.
            @else
                The host has removed this invitation from the guest list.
            @endif
            Its RSVP link and entry pass no longer work. If you think this is a mistake, please get in touch with the host.
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
