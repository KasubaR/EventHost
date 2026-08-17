@extends('layouts.site')

@section('title', 'Page not found | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/errors.css') }}">
@endpush

@section('content')

<section class="err-hero" aria-labelledby="err-title">
    <div class="err-hero-inner">
        <p class="err-code" aria-hidden="true">404</p>
        <span class="err-eyebrow">Page not found</span>
        <h1 id="err-title">We couldn't find that page</h1>
        <p class="err-lead">The link may be wrong, or the event isn't public. Head home, or browse what's on.</p>
        <div class="err-ctas">
            <a href="{{ route('home') }}" class="btn-hero-primary">Home</a>
            <a href="{{ route('events.discover') }}" class="btn-hero-secondary">Discover Events</a>
            @auth
                <a href="{{ route('dashboard') }}" class="btn-hero-secondary">Dashboard</a>
            @endauth
        </div>
    </div>
</section>

@endsection
