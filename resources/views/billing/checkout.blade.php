<x-app-layout>
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/billing.css') }}">
    @endpush

    @push('scripts')
        <script src="{{ asset('js/billing-checkout.js') }}" defer></script>
    @endpush

    <x-slot name="title">Buy event credit</x-slot>

    <x-slot name="pageHeader">
        <div class="dph-inner">
            <div>
                <h1 class="dph-title">Buy event credit</h1>
                <p class="dph-sub">Choose a plan and complete payment to unlock your event.</p>
            </div>
        </div>
    </x-slot>

    <div class="billing-page"
         data-initiate-url="{{ route('payment.initiate') }}"
         data-verify-url="{{ url('/payment/verify') }}"
         data-verify-ref-url="{{ url('/payment/verify-ref') }}"
         data-events-url="{{ route('events.create') }}"
         data-csrf="{{ csrf_token() }}">

        @if (in_array(session('status'), ['premium-required-tables', 'premium-required-checkin', 'premium-required-photos', 'premium-required-qr-badges'], true))
            <div class="evt-flash evt-flash--warn">
                <i class="fa-solid fa-triangle-exclamation"></i>
                QR check-in and the table photo wall are Pro features. Upgrade your plan to unlock them.
            </div>
        @elseif (session('status') === 'no-event-credits')
            <div class="evt-flash evt-flash--warn">
                <i class="fa-solid fa-triangle-exclamation"></i> You have no event credits. Buy one below to publish an event.
            </div>
        @endif

        {{-- Credits bar --}}
        <div class="billing-credits-bar">
            <div class="billing-credits-stat">
                <i class="fa-solid fa-ticket" aria-hidden="true"></i>
                <strong class="billing-credits-val">{{ $user->event_credits }}</strong>
                <span class="billing-credits-lbl">event {{ Str::plural('credit', $user->event_credits) }} remaining</span>
            </div>
            <div class="billing-credits-divider"></div>
            <div class="billing-credits-stat">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <span class="billing-credits-lbl">Current plan:</span>
                <strong class="billing-credits-val">{{ $user->subscriptionTier()->label() }}</strong>
            </div>
        </div>

        {{-- Plan selection --}}
        <section class="billing-section">
            <h2 class="billing-section-title">Choose a plan</h2>
            <p class="billing-section-sub">Each credit unlocks one event. Pick the plan that fits your occasion.</p>
            <div class="billing-plans">
                @foreach ($plans as $key => $plan)
                    @php
                        $icons    = ['base' => 'fa-bolt', 'pro' => 'fa-rocket', 'pro_plus' => 'fa-crown'];
                        $icon     = $icons[$key] ?? 'fa-star';
                        $isPopular  = $key === 'pro';
                        $isSelected = ($selectedPlan ?? 'pro') === $key;
                        $priceLabel = ($currency === 'ZMW' ? 'K' : $currency) . number_format($plan['amount'], 0);
                    @endphp
                    <label class="billing-plan-card {{ $isSelected ? 'is-selected' : '' }} {{ $isPopular ? 'is-popular' : '' }}"
                           data-plan-label="{{ $plan['label'] }}"
                           data-plan-price="{{ $priceLabel }}">
                        <input type="radio" name="plan_key" value="{{ $key }}" class="billing-plan-input"
                               {{ $isSelected ? 'checked' : '' }}>
                        @if ($isPopular)
                            <div class="billing-plan-popular-tag">Most Popular</div>
                        @endif
                        <div class="billing-plan-icon">
                            <i class="fa-solid {{ $icon }}" aria-hidden="true"></i>
                        </div>
                        <div class="billing-plan-name">{{ $plan['label'] }}</div>
                        <div class="billing-plan-price">
                            <span class="billing-plan-currency">{{ $currency === 'ZMW' ? 'K' : $currency }}</span>{{ number_format($plan['amount'], 0) }}
                        </div>
                        <div class="billing-plan-period">per event</div>
                        <ul class="billing-plan-features">
                            @foreach ($plan['features'] as $feature)
                                <li><i class="fa-solid fa-check" aria-hidden="true"></i> {{ str_replace('{template_count}', $activeTemplateCount, $feature) }}</li>
                            @endforeach
                        </ul>
                        <div class="billing-plan-select-indicator">
                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i> Selected
                        </div>
                    </label>
                @endforeach

                {{-- Enterprise is Contact Sales only — custom templates, multi-page sites and bespoke
                     event builds aren't priced or checked out here. Not a <label>/radio like the plans
                     above, and deliberately excluded from billing-checkout.js's card-select binding. --}}
                <div class="billing-plan-card is-static">
                    <div class="billing-plan-icon">
                        <i class="fa-solid fa-gem" aria-hidden="true"></i>
                    </div>
                    <div class="billing-plan-name">Enterprise</div>
                    <div class="billing-plan-price">Custom</div>
                    <div class="billing-plan-period">Custom templates &amp; events</div>
                    <ul class="billing-plan-features">
                        <li><i class="fa-solid fa-check" aria-hidden="true"></i> Everything in Pro+</li>
                        <li><i class="fa-solid fa-check" aria-hidden="true"></i> Custom-designed templates</li>
                        <li><i class="fa-solid fa-check" aria-hidden="true"></i> Multi-page invitation sites</li>
                        <li><i class="fa-solid fa-check" aria-hidden="true"></i> Fully custom event builds</li>
                        <li><i class="fa-solid fa-check" aria-hidden="true"></i> Dedicated designer</li>
                    </ul>
                    <a href="{{ route('contact') }}" class="billing-plan-contact-btn">Contact Sales</a>
                </div>
            </div>
        </section>

        {{-- Checkout row: payment method + order summary --}}
        <div class="billing-checkout">

            <section class="billing-section">
                <h2 class="billing-section-title">Payment method</h2>

                <div class="billing-method-tabs" role="tablist">
                    <button type="button" class="billing-method-tab is-active" data-method="mobile_money" role="tab" aria-selected="true">
                        <i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i>
                        <span>Mobile Money</span>
                    </button>
                    @if ($bankTransferEnabled ?? true)
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
                               value="{{ old('phone', $user->phone) }}" placeholder="097 123 4567" autocomplete="tel">
                        <p class="billing-field-note">Enter the number registered with your mobile money account.</p>
                    </div>
                </div>

                @if ($bankTransferEnabled ?? true)
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
                    <span class="billing-summary-plan-name" id="billingSummaryPlan">
                        @php $defaultPlan = $plans[$selectedPlan ?? 'pro'] ?? reset($plans); @endphp
                        {{ $defaultPlan['label'] }}
                    </span>
                    <span class="billing-summary-plan-price" id="billingSummaryPrice">
                        {{ $currency === 'ZMW' ? 'K' : $currency }}{{ number_format($defaultPlan['amount'], 0) }}
                    </span>
                </div>
                <div class="billing-summary-item">
                    <span>1 event credit</span>
                    <i class="fa-solid fa-ticket" aria-hidden="true"></i>
                </div>
                <button type="button" class="btn-primary billing-pay-btn" id="billingPayBtn">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i> Pay securely
                </button>
                <p class="billing-summary-note">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                    Secured by Lenco. Credits are non-refundable after use.
                </p>
            </div>

        </div>

        <div class="billing-status is-hidden" id="billingStatus" aria-live="polite"></div>
        <div class="billing-instructions is-hidden" id="billingInstructions"></div>
        <div class="billing-bank-details is-hidden" id="billingBankDetails"></div>
    </div>
</x-app-layout>
