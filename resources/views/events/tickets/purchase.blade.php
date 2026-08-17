@extends('layouts.site')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/ticket-checkout.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/ticket-checkout.js') }}" defer></script>
@endpush

@section('title', 'Buy tickets | '.$event->name)

@section('content')
    <article class="tkc-page">
        <div class="tkc-card">
            <header class="tkc-header">
                <p class="tkc-event-badge"><i class="fa-solid fa-ticket" aria-hidden="true"></i> Tickets</p>
                <h1 class="tkc-title">{{ $event->name }}</h1>
                <ul class="tkc-meta">
                    <li>
                        <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                        {{ $event->event_date->format('l, F j, Y') }}
                        @if ($event->event_time)
                            &middot; {{ \Illuminate\Support\Str::substr($event->event_time, 0, 5) }}
                        @endif
                    </li>
                    @if ($event->venue)
                        <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $event->venue }}</li>
                    @endif
                </ul>
                <a href="{{ route('events.public', $event->slug) }}" class="tkc-back-link">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to event
                </a>
            </header>

            @if ($errors->any())
                <div class="tkc-alert tkc-alert--error" role="alert">
                    <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> {{ $errors->first() }}
                </div>
            @endif

            @if ($event->ticketTypes->isEmpty())
                <p class="tkc-muted">Tickets are not available for this event right now.</p>
            @else
                <form method="POST" action="{{ route('events.public.tickets.hold', $event->slug) }}" class="tkc-picker">
                    @csrf
                    <h2 class="tkc-section-title">Choose your tickets</h2>

                    @foreach ($event->ticketTypes as $ticketType)
                        @php
                            $available = $ticketType->availableQuantity();
                            $purchasable = $ticketType->isPurchasable();
                            $maxQty = $purchasable ? min($ticketType->max_per_order, $available ?? $ticketType->max_per_order) : 0;
                            $defaultQty = $purchasable ? min($ticketType->min_per_order, $maxQty) : 0;
                        @endphp
                        <div class="tkc-type-row {{ ! $purchasable ? 'is-disabled' : '' }}">
                            <div class="tkc-type-info">
                                <div class="tkc-type-name">{{ $ticketType->name }}</div>
                                @if ($ticketType->description)
                                    <p class="tkc-type-desc">{{ $ticketType->description }}</p>
                                @endif
                                @if (! $purchasable)
                                    <p class="tkc-type-note">{{ $available === 0 ? 'Sold out' : 'Sales closed' }}</p>
                                @elseif ($available !== null && $available <= 10)
                                    <p class="tkc-type-note">Only {{ $available }} left</p>
                                @endif
                            </div>
                            <div class="tkc-type-price">K{{ number_format((float) $ticketType->price, 2) }}</div>
                            <div class="tkc-type-qty" @if ($purchasable) data-tkc-qty @endif>
                                <span class="tkc-type-qty-label" id="qty-label-{{ $ticketType->id }}">Qty</span>
                                <div class="tkc-type-qty-stepper">
                                    <button type="button" class="tkc-type-qty-btn" data-tkc-qty-dec @disabled(! $purchasable) aria-label="Decrease quantity for {{ $ticketType->name }}">
                                        <i class="fa-solid fa-minus" aria-hidden="true"></i>
                                    </button>
                                    <input
                                        type="number"
                                        name="quantities[{{ $ticketType->id }}]"
                                        min="0"
                                        max="{{ $maxQty }}"
                                        value="{{ old('quantities.'.$ticketType->id, $defaultQty) }}"
                                        inputmode="numeric"
                                        {{ ! $purchasable ? 'disabled' : '' }}
                                        aria-labelledby="qty-label-{{ $ticketType->id }}"
                                    >
                                    <button type="button" class="tkc-type-qty-btn" data-tkc-qty-inc @disabled(! $purchasable) aria-label="Increase quantity for {{ $ticketType->name }}">
                                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach

                    <button type="submit" class="btn-primary tkc-submit-btn">
                        <i class="fa-solid fa-credit-card" aria-hidden="true"></i> Continue to checkout
                    </button>
                    <p class="tkc-hold-note">Selected tickets are held for you for 10 minutes.</p>
                </form>
            @endif
        </div>
    </article>
@endsection
