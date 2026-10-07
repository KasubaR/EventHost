@extends('layouts.site')

@section('title', 'Too many attempts | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/errors.css') }}">
@endpush

@php
    $retryAfter = (int) ($exception->getHeaders()['Retry-After'] ?? 0);
@endphp

@section('content')

<section class="err-hero" aria-labelledby="err-title">
    <div class="err-hero-inner">
        <p class="err-code" aria-hidden="true">429</p>
        <span class="err-eyebrow">Too many attempts</span>
        <h1 id="err-title">Please wait a moment and try again</h1>
        <p class="err-lead">
            @if ($retryAfter > 0)
                That was sent too many times in a row. You can try again in {{ $retryAfter }} {{ $retryAfter === 1 ? 'second' : 'seconds' }}.
            @else
                That was sent too many times in a row. Give it a minute, then try again.
            @endif
            Nothing you entered was lost if you go back.
        </p>
        <div class="err-ctas">
            <a href="{{ url()->previous() }}" class="btn-hero-primary">Go back</a>
            <a href="{{ route('home') }}" class="btn-hero-secondary">Home</a>
        </div>
    </div>
</section>

@endsection
