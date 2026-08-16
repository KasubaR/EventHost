@extends('layouts.site')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/ticket-checkout.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/ticket-checkout.js') }}" defer></script>
@endpush

@section('title', 'Checkout | '.$event->name)

@section('content')
    @php
        $faceValue = $reservations->sum(fn ($r) => (float) $r->unit_price_snapshot * $r->quantity);
        $isPassThrough = $event->commission_mode?->value === 'pass_through';
        $commissionPercent = \App\Support\TicketingSettings::commissionPercent();
        $commissionAmount = round($faceValue * (float) $commissionPercent / 100, 2);
        $buyerTotal = $isPassThrough ? $faceValue + $commissionAmount : $faceValue;
        $earliestExpiry = $reservations->min('expires_at');
    @endphp

    <article class="tkc-page"
             data-checkout-root
             data-checkout-url="{{ route('events.public.tickets.checkout.store', $event->slug) }}"
             data-order-status-base="{{ url('/tickets/orders') }}"
             data-csrf="{{ csrf_token() }}"
             data-hold-expires-at="{{ $earliestExpiry?->toIso8601String() }}">
        <div class="tkc-card">
            <header class="tkc-header">
                <p class="tkc-event-badge"><i class="fa-solid fa-ticket" aria-hidden="true"></i> Checkout</p>
                <h1 class="tkc-title">{{ $event->name }}</h1>
                <a href="{{ route('events.public.tickets', $event->slug) }}" class="tkc-back-link">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Change tickets
                </a>
            </header>

            <div class="tkc-hold-timer" id="tkcHoldTimer" aria-live="polite">
                <i class="fa-regular fa-clock" aria-hidden="true"></i>
                <span>Your tickets are held for <strong id="tkcHoldTimerValue">10:00</strong></span>
            </div>

            <div class="tkc-checkout-grid">
                <section class="tkc-form-section">
                    <h2 class="tkc-section-title">Your details</h2>
                    <div class="tkc-field">
                        <label class="tkc-label" for="tkc-name">Full name</label>
                        <input id="tkc-name" type="text" class="tkc-input" name="name" required autocomplete="name">
                    </div>
                    <div class="tkc-field">
                        <label class="tkc-label" for="tkc-email">Email</label>
                        <input id="tkc-email" type="email" class="tkc-input" name="email" required autocomplete="email">
                        <p class="tkc-field-note">Your tickets and QR codes are sent here.</p>
                    </div>
                    <div class="tkc-field">
                        <label class="tkc-label" for="tkc-phone">Phone (optional)</label>
                        <input id="tkc-phone" type="tel" class="tkc-input" name="phone" autocomplete="tel">
                    </div>

                    <h2 class="tkc-section-title">Payment method</h2>
                    <div class="tkc-method-tabs" role="tablist">
                        <button type="button" class="tkc-method-tab is-active" data-method="mobile_money" role="tab" aria-selected="true">
                            <i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i> Mobile Money
                        </button>
                        @if ($bankTransferEnabled)
                            <button type="button" class="tkc-method-tab" data-method="bank_transfer" role="tab" aria-selected="false">
                                <i class="fa-solid fa-building-columns" aria-hidden="true"></i> Bank Transfer
                            </button>
                        @endif
                    </div>

                    <div class="tkc-method-panel is-active" data-panel="mobile_money">
                        <div class="tkc-provider-grid">
                            <label class="tkc-provider-option">
                                <input type="radio" name="provider" value="mtn" checked>
                                <span class="tkc-provider-card">MTN Money</span>
                            </label>
                            <label class="tkc-provider-option">
                                <input type="radio" name="provider" value="airtel">
                                <span class="tkc-provider-card">Airtel Money</span>
                            </label>
                        </div>
                        <div class="tkc-field">
                            <label class="tkc-label" for="tkc-momo-phone">Mobile money number</label>
                            <input id="tkc-momo-phone" type="tel" class="tkc-input" name="momo_phone" placeholder="097 123 4567">
                        </div>
                    </div>

                    @if ($bankTransferEnabled)
                        <div class="tkc-method-panel" data-panel="bank_transfer">
                            <div class="tkc-field">
                                <label class="tkc-label" for="tkc-bank">Bank</label>
                                <input id="tkc-bank" type="text" class="tkc-input" name="bank_name" placeholder="Your bank">
                            </div>
                        </div>
                    @endif
                </section>

                <aside class="tkc-summary-card">
                    <div class="tkc-summary-header">Order summary</div>
                    @foreach ($reservations as $reservation)
                        <div class="tkc-summary-item">
                            <span>{{ $reservation->quantity }} × {{ $reservation->ticketType->name }}</span>
                            <span>K{{ number_format((float) $reservation->unit_price_snapshot * $reservation->quantity, 2) }}</span>
                        </div>
                    @endforeach
                    @if ($isPassThrough)
                        <div class="tkc-summary-item">
                            <span>EventHost booking fee</span>
                            <span>K{{ number_format($commissionAmount, 2) }}</span>
                        </div>
                    @endif
                    <div class="tkc-summary-total">
                        <span>Total</span>
                        <span>K{{ number_format($buyerTotal, 2) }}</span>
                    </div>
                    <button type="button" class="btn-primary tkc-pay-btn" id="tkcPayBtn">
                        <i class="fa-solid fa-lock" aria-hidden="true"></i> Pay securely
                    </button>
                    <p class="tkc-summary-note">
                        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Secured by Lenco.
                    </p>
                </aside>
            </div>

            <div class="tkc-status is-hidden" id="tkcStatus" aria-live="polite"></div>
        </div>
    </article>
@endsection
