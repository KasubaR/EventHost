@extends('layouts.site')
@php $guestPage = true; @endphp

@section('title', 'Invitation link not found | '.config('app.name'))

@push('head')
    <link rel="stylesheet" href="{{ asset('css/errors.css') }}">
@endpush

@section('content')

<section class="err-hero" aria-labelledby="err-title">
    <div class="err-hero-inner">
        <p class="err-code" aria-hidden="true">404</p>
        <span class="err-eyebrow">Invitation link not found</span>
        <h1 id="err-title">We couldn't find this invitation</h1>
        <p class="err-lead">
            The link may be incomplete. If it was split across two lines in a message, copy the whole link and try again.
            It may also have been replaced or removed by the host. Ask the person who invited you to send it again.
        </p>
        <div class="err-ctas">
            <a href="{{ route('home') }}" class="btn-hero-secondary">Home</a>
        </div>
    </div>
</section>

@endsection
