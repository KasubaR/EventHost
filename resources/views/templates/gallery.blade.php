@extends('layouts.site')

@section('title', 'Invitation templates | Event Host')

@push('head')
    <meta name="description" content="Browse every Event Host invitation template, grouped by plan. Preview any design before you sign up.">
    <link rel="stylesheet" href="{{ asset('css/templates-gallery.css') }}">
@endpush

@php
    $money = fn (float $amount): string => ($currency === 'ZMW' ? 'K' : $currency.' ').number_format($amount);
    $filterName = $category?->name;

    $groups = [
        [
            'id' => 'base',
            'kicker' => 'Included with Base',
            'title' => 'Base templates',
            'lead' => 'Clean, ready-to-share invitations with RSVP built in. A simple way to get every guest the details.',
            'price' => $basePrice,
            'templates' => $baseTemplates,
        ],
        [
            'id' => 'pro',
            'kicker' => 'Pro and above',
            'title' => 'Pro templates',
            'lead' => 'Richer layouts with photo galleries, countdowns and full-screen heroes. Pro+ and Enterprise include them too.',
            'price' => $proPrice,
            'templates' => $proTemplates,
        ],
    ];
@endphp

@section('content')

<section class="tplg-hero">
    <div class="tplg-hero-inner">
        <h1>Invitations your guests will <span>remember</span></h1>
        <p>
            {{ $templateCount }} {{ \Illuminate\Support\Str::plural('design', $templateCount) }} for weddings, celebrations, ceremonies and more.
            Preview any of them with sample details before you sign up, then make it yours.
        </p>
        <div class="tplg-hero-links">
            <a href="#base" class="btn-hero-secondary">Base templates <span class="tplg-count">{{ $baseTemplates->count() }}</span></a>
            <a href="#pro" class="btn-hero-primary">Pro templates <span class="tplg-count">{{ $proTemplates->count() }}</span></a>
        </div>
    </div>
</section>

<div class="tplg-wrap">
    @if ($categories->count() > 1)
        <nav class="tplg-chips" aria-label="Filter templates by category">
            <a href="{{ route('templates.gallery') }}" @class(['tplg-chip', 'is-active' => $category === null]) @if ($category === null) aria-current="page" @endif>All</a>
            @foreach ($categories as $cat)
                @php $isActive = $category?->is($cat); @endphp
                <a href="{{ route('templates.gallery', ['category' => $cat->slug]) }}" @class(['tplg-chip', 'is-active' => $isActive]) @if ($isActive) aria-current="page" @endif>{{ $cat->name }}</a>
            @endforeach
        </nav>
    @endif

    @foreach ($groups as $group)
        <section class="tplg-group tplg-group--{{ $group['id'] }}" id="{{ $group['id'] }}" aria-labelledby="tplg-{{ $group['id'] }}-title">
            <header class="tplg-group-head">
                <div class="tplg-group-intro">
                    <span class="tplg-kicker">{{ $group['kicker'] }}</span>
                    <h2 id="tplg-{{ $group['id'] }}-title">{{ $group['title'] }}</h2>
                    <p>{{ $group['lead'] }}</p>
                </div>
                @if ($group['price'] > 0)
                    <div class="tplg-price">
                        <span class="tplg-price-from">From</span>
                        <strong>{{ $money($group['price']) }}</strong>
                        <span class="tplg-price-per">per event</span>
                    </div>
                @endif
            </header>

            @if ($group['templates']->isEmpty())
                <p class="tplg-empty">
                    @if ($filterName)
                        No {{ $group['title'] }} in {{ $filterName }} yet. <a href="{{ route('templates.gallery') }}#{{ $group['id'] }}">See all {{ $group['title'] }}</a>
                    @else
                        New {{ $group['title'] }} are on the way.
                    @endif
                </p>
            @else
                <div class="tplg-grid">
                    @foreach ($group['templates'] as $tpl)
                        @include('templates.partials.gallery-card', ['tpl' => $tpl])
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach
</div>

<section class="tplg-band">
    <div class="tplg-band-inner">
        <div>
            <h2>Need something bespoke?</h2>
            <p>Enterprise covers custom-designed templates, multi-page invitation sites and fully bespoke event builds.</p>
        </div>
        <div class="tplg-band-actions">
            <a href="{{ route('contact') }}" class="btn-hero-secondary">Talk to us</a>
            @guest
                <a href="{{ route('register') }}" class="btn-hero-primary">Get started free</a>
            @endguest
        </div>
    </div>
</section>

@endsection
