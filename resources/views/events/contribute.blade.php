@extends('layouts.site')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/ticket-checkout.css') }}">
    <link rel="stylesheet" href="{{ asset('css/contributions.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/contribution-checkout.js') }}" defer></script>
@endpush

@section('title', 'Contribute | '.$event->name)

@section('content')
    <article class="tkc-page"
             data-contribute-root
             data-contribute-url="{{ route('events.public.contribute.store', $event->slug) }}"
             data-contribution-status-base="{{ url('/contributions') }}"
             data-csrf="{{ csrf_token() }}">
        <div class="tkc-card">
            <header class="tkc-header">
                <p class="tkc-event-badge"><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i> Contribution</p>
                <h1 class="tkc-title">{{ $event->name }}</h1>
                <a href="{{ route('events.public', $event->slug) }}" class="tkc-back-link">
                    <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to invitation
                </a>
            </header>

            <div class="ctb-amount-banner">
                <span>Requested contribution</span>
                <strong>K{{ number_format((float) $event->contribution_amount, 2) }}</strong>
                <p class="ctb-amount-note">You can pay this in one go or in installments — enter what you're paying now below.</p>
            </div>

            <div class="tkc-checkout-grid">
                <section class="tkc-form-section">
                    <form id="ctbContributeForm" class="tkc-checkout-form">
                        <h2 class="tkc-section-title">Your details</h2>
                        <div class="tkc-field">
                            <label class="tkc-label" for="ctb-name">Full name</label>
                            <input id="ctb-name" type="text" class="tkc-input" name="name" required autocomplete="name" value="{{ old('name') }}">
                        </div>
                        <div class="tkc-field">
                            <label class="tkc-label" for="ctb-phone">Phone</label>
                            <input id="ctb-phone" type="tel" class="tkc-input" name="phone" required autocomplete="tel" placeholder="097 123 4567" value="{{ old('phone') }}">
                            <p class="tkc-field-note">Used to find your contribution again if you come back to pay more.</p>
                        </div>
                        <div class="tkc-field">
                            <label class="tkc-label" for="ctb-email">Email <span class="profile-optional">optional</span></label>
                            <input id="ctb-email" type="email" class="tkc-input" name="email" autocomplete="email" value="{{ old('email') }}">
                        </div>

                        <h2 class="tkc-section-title">Amount</h2>
                        <div class="tkc-field">
                            <label class="tkc-label" for="ctb-amount">How much are you paying now?</label>
                            <input id="ctb-amount" type="number" step="0.01" min="1" max="{{ (float) $event->contribution_amount }}"
                                   class="tkc-input" name="amount" required
                                   value="{{ old('amount', number_format((float) $event->contribution_amount, 2, '.', '')) }}">
                            <p class="tkc-field-note">Up to K{{ number_format((float) $event->contribution_amount, 2) }}. Paying less starts an installment plan you can finish later.</p>
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
                                    <span class="tkc-provider-card">
                                        <img src="{{ asset('images/icon/Mtn-MoMo-Pay.svg') }}" alt="MTN MoMo" class="tkc-provider-logo">
                                    </span>
                                </label>
                                <label class="tkc-provider-option">
                                    <input type="radio" name="provider" value="airtel">
                                    <span class="tkc-provider-card">
                                        <img src="{{ asset('images/icon/Airtel-Money-1.svg') }}" alt="Airtel Money" class="tkc-provider-logo">
                                    </span>
                                </label>
                            </div>
                            <div class="tkc-field">
                                <label class="tkc-label" for="ctb-momo-phone">Mobile money number</label>
                                <input id="ctb-momo-phone" type="tel" class="tkc-input" name="momo_phone" placeholder="097 123 4567" value="{{ old('momo_phone') }}">
                            </div>
                        </div>

                        @if ($bankTransferEnabled)
                            <div class="tkc-method-panel" data-panel="bank_transfer">
                                <div class="tkc-field">
                                    <label class="tkc-label" for="ctb-bank">Bank</label>
                                    <input id="ctb-bank" type="text" class="tkc-input" name="bank_name" placeholder="Your bank">
                                </div>
                            </div>
                        @endif

                        <button type="submit" class="btn-primary tkc-pay-btn" id="ctbPayBtn">
                            <i class="fa-solid fa-lock" aria-hidden="true"></i> Pay securely
                        </button>
                    </form>
                </section>
            </div>

            <div class="tkc-status is-hidden" id="ctbStatus" aria-live="polite"></div>
        </div>
    </article>
@endsection
