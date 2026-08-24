@extends('layouts.site')

@section('title', 'Access denied | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/errors.css') }}">
@endpush

@section('content')

<section class="err-hero" aria-labelledby="err-title">
    <div class="err-hero-inner">
        <p class="err-code" aria-hidden="true">403</p>
        <span class="err-eyebrow">Access denied</span>
        <h1 id="err-title">That link isn't valid anymore</h1>
        <p class="err-lead">This usually happens when a link has expired or was already used — verification and some other links only stay valid for a short time.</p>
        <div class="err-ctas">
            @auth
                <a href="{{ route('verification.notice') }}" class="btn-hero-primary">Request a new link</a>
                <a href="{{ route('dashboard') }}" class="btn-hero-secondary">Dashboard</a>
            @else
                <a href="{{ route('login') }}" class="btn-hero-primary">Sign in</a>
                <a href="{{ route('home') }}" class="btn-hero-secondary">Home</a>
            @endauth
        </div>
    </div>
</section>

@endsection
