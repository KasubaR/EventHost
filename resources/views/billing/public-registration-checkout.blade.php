<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/billing.css') }}">
    @endpush

    @push('scripts')
        <script src="{{ asset('js/public-registration-checkout.js') }}" defer></script>
    @endpush

    <x-slot name="title">Pay registration quote — {{ $event->name }}</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Pay your public event quote</h1>
                <p class="dph-sub">{{ $event->name }}</p>
            </div>
            <div class="evt-card-actions">
                <a href="{{ route('events.show', $event) }}" class="evt-btn-outline"><i class="fa-solid fa-arrow-left"></i> Back to event</a>
            </div>
        </div>
    </x-slot>

    <div class="billing-page"
         data-initiate-url="{{ route('payment.initiate') }}"
         data-verify-url="{{ url('/payment/verify') }}"
         data-verify-ref-url="{{ url('/payment/verify-ref') }}"
         data-event-url="{{ route('events.show', $event) }}"
         data-event-id="{{ $event->id }}"
         data-csrf="{{ csrf_token() }}">

        {{-- Checkout row: payment method + order summary --}}
        <div class="billing-checkout">

            <section class="billing-section">
                <h2 class="billing-section-title">What this does</h2>
                <p class="billing-section-sub">
                    EventHost reviewed "{{ $event->name }}" and approved it for {{ $currency === 'ZMW' ? 'K' : $currency }}{{ number_format($amount, 2) }}.
                    Paying this quote makes the invitation live at its public link — one-time, this event only.
                </p>

                <h2 class="billing-section-title">Payment method</h2>

                <div class="billing-method-tabs" role="tablist">
                    <button type="button" class="billing-method-tab is-active" data-method="mobile_money" role="tab" aria-selected="true">
                        <i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i>
                        <span>Mobile Money</span>
                    </button>
                    @if ($bankTransferEnabled)
                        <button type="button" class="billing-method-tab" data-method="bank_transfer" role="tab" aria-selected="false">
                            <i class="fa-solid fa-building-columns" aria-hidden="true"></i>
                            <span>Bank Transfer</span>
                        </button>
                    @endif
                </div>

                <div class="billing-method-panel is-active" data-panel="mobile_money">
                    <div class="billing-provider-grid">
                        <label class="billing-provider-option">
                            <input type="radio" name="provider" value="mtn" checked>
                            <span class="billing-provider-card billing-provider-mtn">
                                <span class="billing-provider-icon">MTN</span>
                                <span class="billing-provider-name">MTN Money</span>
                            </span>
                        </label>
                        <label class="billing-provider-option">
                            <input type="radio" name="provider" value="airtel">
                            <span class="billing-provider-card billing-provider-airtel">
                                <span class="billing-provider-icon">Airtel</span>
                                <span class="billing-provider-name">Airtel Money</span>
                            </span>
                        </label>
                    </div>
                    <div class="billing-phone-field">
                        <label class="profile-label" for="billing-phone">
                            <i class="fa-solid fa-phone" aria-hidden="true"></i> Mobile number
                        </label>
                        <input id="billing-phone" type="tel" class="profile-input" name="phone"
                               value="{{ old('phone', auth()->user()->phone) }}" placeholder="097 123 4567" autocomplete="tel">
                        <p class="billing-field-note">Enter the number registered with your mobile money account.</p>
                    </div>
                </div>

                @if ($bankTransferEnabled)
                    <div class="billing-method-panel" data-panel="bank_transfer">
                        <div class="billing-phone-field">
                            <label class="profile-label" for="billing-bank">
                                <i class="fa-solid fa-building-columns" aria-hidden="true"></i> Select your bank
                            </label>
                            <select id="billing-bank" class="profile-input" name="bank_name">
                                <option value="">Choose a bank…</option>
                                @foreach ($banks as $bank)
                                    @php
                                        $bankName = is_array($bank) ? ($bank['name'] ?? $bank['bankName'] ?? $bank['bank_name'] ?? '') : (string) $bank;
                                    @endphp
                                    @if ($bankName !== '')
                                        <option value="{{ $bankName }}">{{ $bankName }}</option>
                                    @endif
                                @endforeach
                            </select>
                            @if (count($banks) === 0)
                                <p class="billing-field-note">Bank list unavailable. Try mobile money or contact support.</p>
                            @endif
                        </div>
                    </div>
                @endif
            </section>

            {{-- Order summary --}}
            <div class="billing-summary-card">
                <div class="billing-summary-header">Order summary</div>
                <div class="billing-summary-plan">
                    <span class="billing-summary-plan-name">Public Registration Fee</span>
                    <span class="billing-summary-plan-price">{{ $currency === 'ZMW' ? 'K' : $currency }}{{ number_format($amount, 0) }}</span>
                </div>
                <div class="billing-summary-item">
                    <span>{{ $event->name }}</span>
                    <i class="fa-solid fa-earth-africa" aria-hidden="true"></i>
                </div>
                <button type="button" class="btn-primary billing-pay-btn" id="billingPayBtn">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i> Pay securely
                </button>
                <p class="billing-summary-note">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    Secured by Lenco. Non-refundable once your event goes live.
                </p>
            </div>

        </div>

        <div class="billing-status is-hidden" id="billingStatus" aria-live="polite"></div>
        <div class="billing-instructions is-hidden" id="billingInstructions"></div>
        <div class="billing-bank-details is-hidden" id="billingBankDetails"></div>
    </div>
</x-app-layout>
