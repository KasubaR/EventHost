@extends('layouts.site')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/ticket-checkout.css') }}">
    <link rel="stylesheet" href="{{ asset('css/contributions.css') }}">
@endpush

@push('scripts')
    <script src="{{ asset('js/contribution-status.js') }}" defer></script>
@endpush

@php
    $latestPayment = $contribution->payments->first();
    $isTerminalPending = $latestPayment && $latestPayment->isTerminal() && ! $latestPayment->canRecoverToCompleted() && $latestPayment->status !== 'completed';
    $percent = (float) $contribution->target_amount > 0
        ? min(100, round((float) $contribution->amount_paid / (float) $contribution->target_amount * 100))
        : 0;
@endphp

@section('title', 'Your contribution | '.$contribution->event->name)

@section('content')
    <article class="tkc-page"
             data-contribution-status-root
             data-verify-url="{{ route('contributions.verify', $contribution->reference) }}"
             data-pay-url="{{ route('contributions.pay', $contribution->reference) }}"
             data-csrf="{{ csrf_token() }}"
             data-status="{{ $contribution->status->value }}"
             data-completed="{{ $contribution->isCompleted() ? '1' : '0' }}"
             data-latest-payment-terminal="{{ $latestPayment && $latestPayment->isTerminal() ? '1' : '0' }}">
        <div class="tkc-card tkc-card--narrow">
            <header class="tkc-header">
                <p class="tkc-event-badge"><i class="fa-solid fa-hand-holding-dollar" aria-hidden="true"></i> Contribution</p>
                <h1 class="tkc-title">{{ $contribution->event->name }}</h1>
            </header>

            <div class="ctb-progress">
                <div class="ctb-progress-track">
                    <div class="ctb-progress-fill" style="width: {{ $percent }}%"></div>
                </div>
                <div class="ctb-progress-labels">
                    <span>K{{ number_format((float) $contribution->amount_paid, 2) }} paid</span>
                    <span>of K{{ number_format((float) $contribution->target_amount, 2) }}</span>
                </div>
            </div>

            @if ($contribution->isCompleted())
                <div class="tkc-result tkc-result--success">
                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                    <h2 class="tkc-title">Paid in full — thank you!</h2>
                    <p class="tkc-muted">Your contribution of K{{ number_format((float) $contribution->target_amount, 2) }} is complete.</p>
                </div>
            @elseif ($latestPayment && $latestPayment->status === 'processing')
                <div class="tkc-result tkc-result--pending">
                    <i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i>
                    <h2 class="tkc-title">Approve the payment on your phone</h2>
                    <p class="tkc-muted">A mobile money prompt has been sent. Confirm it to add this installment.</p>
                </div>
            @elseif ($latestPayment && $latestPayment->status === 'pending')
                <div class="tkc-result tkc-result--pending">
                    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i>
                    <h2 class="tkc-title">Starting your payment…</h2>
                    <p class="tkc-muted">We're contacting the payment provider. This usually takes a few seconds.</p>
                </div>
            @else
                @if ($isTerminalPending)
                    <p class="tkc-field-note ctb-mt-sm">{{ $latestPayment->failure_reason ?? 'That payment did not go through. You can try again below.' }}</p>
                @endif

                <section class="tkc-form-section ctb-mt-md">
                    <form id="ctbPayMoreForm" class="tkc-checkout-form">
                        <h2 class="tkc-section-title">Pay the remaining K{{ number_format($contribution->remainingAmount(), 2) }}</h2>
                        <div class="tkc-field">
                            <label class="tkc-label" for="ctb-more-amount">Amount</label>
                            <input id="ctb-more-amount" type="number" step="0.01" min="1" max="{{ $contribution->remainingAmount() }}"
                                   class="tkc-input" name="amount" required
                                   value="{{ number_format($contribution->remainingAmount(), 2, '.', '') }}">
                        </div>

                        <div class="tkc-method-tabs" role="tablist">
                            <button type="button" class="tkc-method-tab is-active" data-method="mobile_money" role="tab" aria-selected="true">
                                <i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i> Mobile Money
                            </button>
                            @if ($bankTransferEnabled ?? config('services.lenco.bank_transfer_enabled', true))
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
                                <label class="tkc-label" for="ctb-more-momo-phone">Mobile money number</label>
                                <input id="ctb-more-momo-phone" type="tel" class="tkc-input" name="momo_phone" placeholder="097 123 4567">
                            </div>
                        </div>

                        <div class="tkc-method-panel" data-panel="bank_transfer">
                            <div class="tkc-field">
                                <label class="tkc-label" for="ctb-more-bank">Bank</label>
                                <input id="ctb-more-bank" type="text" class="tkc-input" name="bank_name" placeholder="Your bank">
                            </div>
                        </div>

                        <button type="submit" class="btn-primary tkc-pay-btn" id="ctbPayMoreBtn">
                            <i class="fa-solid fa-lock" aria-hidden="true"></i> Pay this installment
                        </button>
                    </form>
                </section>
            @endif

            @if ($contribution->payments->isNotEmpty())
                <div class="ctb-history">
                    <h2 class="tkc-section-title">Payment history</h2>
                    @foreach ($contribution->payments as $payment)
                        <div class="ctb-history-row">
                            <span>{{ $payment->created_at->format('j M Y, H:i') }}</span>
                            <span>K{{ number_format((float) $payment->amount, 2) }}</span>
                            <span class="ctb-history-status ctb-history-status--{{ $payment->status }}">{{ ucfirst($payment->status) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="tkc-status is-hidden" id="ctbMoreStatus" aria-live="polite"></div>
        </div>
    </article>
@endsection
