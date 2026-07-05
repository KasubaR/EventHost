@extends('layouts.site')

@section('title', 'Create your account | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
@endpush

@php
    $initialStep = 1;
    if ($errors->has('password') || $errors->has('password_confirmation')) {
        $initialStep = 2;
    } elseif ($errors->has('phone') || $errors->has('company_name')) {
        $initialStep = 3;
    } elseif ($errors->any()) {
        $initialStep = 1;
    }
@endphp

@section('content')

{{-- ── HERO ── --}}
<section class="auth-hero">
  <div class="auth-hero-inner">

    <nav class="auth-breadcrumb" aria-label="Breadcrumb">
      <ol>
        <li><a href="{{ url('/') }}"><i class="fa-solid fa-house"></i> Home</a></li>
        <li aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></li>
        <li><span aria-current="page">Sign Up</span></li>
      </ol>
    </nav>

    <h1 class="auth-hero-headline">
      Create your<br>
      <span class="auth-hero-accent">Event Host</span> account
    </h1>
    <p class="auth-hero-sub">
      Three quick steps and you are in. Verify your email, buy an event credit when you are ready, then publish your first invitation.
    </p>

    <div class="auth-hero-stats">
      <div class="auth-stat">
        <div class="auth-stat-n">3</div>
        <div class="auth-stat-l">Simple steps</div>
      </div>
      <div class="auth-stat-sep"></div>
      <div class="auth-stat">
        <div class="auth-stat-n">2 min</div>
        <div class="auth-stat-l">To sign up</div>
      </div>
      <div class="auth-stat-sep"></div>
      <div class="auth-stat">
        <div class="auth-stat-n">K450</div>
        <div class="auth-stat-l">From per event</div>
      </div>
    </div>

  </div>
</section>

{{-- ── FORM SECTION ── --}}
<section class="auth-form-section">
  <div class="auth-form-wrap">

    <div class="auth-form-card">

      <div class="auth-card-header">
        <h2>Create your account</h2>
        <p>Already have an account? <a href="{{ route('login') }}">Sign in</a></p>
      </div>

      <div class="auth-wizard" aria-label="Registration progress">
        <div class="auth-wizard-steps">
          <div class="auth-wizard-step" data-step="1">
            <span class="auth-wizard-step-num">1</span>
            <span class="auth-wizard-step-label">About you</span>
          </div>
          <div class="auth-wizard-step" data-step="2">
            <span class="auth-wizard-step-num">2</span>
            <span class="auth-wizard-step-label">Password</span>
          </div>
          <div class="auth-wizard-step" data-step="3">
            <span class="auth-wizard-step-num">3</span>
            <span class="auth-wizard-step-label">Finish up</span>
          </div>
        </div>
        <p class="auth-step-hint">Step 1 of 3 — takes about 30 seconds</p>
      </div>

      <form method="POST" action="{{ route('register') }}" id="register-form" data-initial-step="{{ $initialStep }}">
        @csrf
        <input type="hidden" name="account_type" id="account_type" value="{{ old('account_type', 'individual') }}">

        {{-- Step 1: About you --}}
        <div class="auth-step-panel" data-step="1">
          <h3 class="auth-step-panel-title">Tell us about yourself</h3>
          <p class="auth-step-panel-desc">Choose your account type and add the email you will use to sign in.</p>

          <div class="auth-fields">
            <div class="auth-field">
              <label class="auth-label"><i class="fa-solid fa-id-card"></i> Account type</label>
              <div class="auth-type-toggle" id="auth-type-toggle">
                <button type="button" class="auth-type-btn auth-type-btn--active" data-type="individual">
                  <i class="fa-solid fa-user"></i> Individual
                </button>
                <button type="button" class="auth-type-btn" data-type="organisation">
                  <i class="fa-solid fa-building"></i> Organisation
                </button>
              </div>
            </div>

            <div class="auth-field">
              <label for="name" class="auth-label" id="name-label">
                <i class="fa-solid fa-user"></i> Full name
              </label>
              <input id="name" type="text" name="name" value="{{ old('name') }}"
                     class="auth-input {{ $errors->has('name') ? 'auth-input--error' : '' }}"
                     placeholder="Your full name" required autofocus autocomplete="name">
              @error('name')
                <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
              @enderror
            </div>

            <div class="auth-field">
              <label for="email" class="auth-label">
                <i class="fa-solid fa-envelope"></i> Email address
              </label>
              <input id="email" type="email" name="email" value="{{ old('email') }}"
                     class="auth-input {{ $errors->has('email') ? 'auth-input--error' : '' }}"
                     placeholder="you@example.com" required autocomplete="username">
              @error('email')
                <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
              @enderror
            </div>
          </div>
        </div>

        {{-- Step 2: Password --}}
        <div class="auth-step-panel" data-step="2" hidden>
          <h3 class="auth-step-panel-title">Secure your account</h3>
          <p class="auth-step-panel-desc">Use at least 8 characters. You can show the password to double-check before continuing.</p>

          <div class="auth-fields">
            <div class="auth-field">
              <label for="password" class="auth-label">
                <i class="fa-solid fa-lock"></i> Password
              </label>
              <div class="auth-input-wrap">
                <input id="password" type="password" name="password"
                       class="auth-input {{ $errors->has('password') ? 'auth-input--error' : '' }}"
                       placeholder="Min. 8 characters" required autocomplete="new-password">
                <button type="button" class="auth-eye" data-target="password" aria-label="Toggle password visibility">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
              @error('password')
                <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
              @enderror
            </div>

            <div class="auth-field">
              <label for="password_confirmation" class="auth-label">
                <i class="fa-solid fa-lock"></i> Confirm password
              </label>
              <div class="auth-input-wrap">
                <input id="password_confirmation" type="password" name="password_confirmation"
                       class="auth-input {{ $errors->has('password_confirmation') ? 'auth-input--error' : '' }}"
                       placeholder="Repeat your password" required autocomplete="new-password">
                <button type="button" class="auth-eye" data-target="password_confirmation" aria-label="Toggle password visibility">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
              @error('password_confirmation')
                <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
              @enderror
            </div>
          </div>
        </div>

        {{-- Step 3: Optional + submit --}}
        <div class="auth-step-panel" data-step="3" hidden>
          <h3 class="auth-step-panel-title">Almost done</h3>
          <p class="auth-step-panel-desc">Phone and business name are optional — add them now or update later in your profile.</p>

          <div class="auth-fields">
            <div class="auth-field">
              <label for="phone" class="auth-label">
                <i class="fa-solid fa-phone"></i> Phone number <span class="auth-optional-tag">optional</span>
              </label>
              <input id="phone" type="tel" name="phone" value="{{ old('phone') }}"
                     class="auth-input {{ $errors->has('phone') ? 'auth-input--error' : '' }}"
                     placeholder="+260 97 000 0000" autocomplete="tel">
              @error('phone')
                <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
              @enderror
            </div>

            <div class="auth-field" id="company-field">
              <label for="company_name" class="auth-label">
                <i class="fa-solid fa-building"></i> Company / Event business name <span class="auth-optional-tag">optional</span>
              </label>
              <input id="company_name" type="text" name="company_name" value="{{ old('company_name') }}"
                     class="auth-input {{ $errors->has('company_name') ? 'auth-input--error' : '' }}"
                     placeholder="Your business name (if any)" autocomplete="organization">
              @error('company_name')
                <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
              @enderror
            </div>
          </div>

          <p class="auth-terms auth-terms--step">
            By creating an account you agree to our <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>.
          </p>
        </div>

        <div class="auth-step-nav">
          <button type="button" class="auth-step-back" hidden>
            <i class="fa-solid fa-arrow-left"></i> Back
          </button>
          <button type="button" class="auth-step-next">
            Continue <i class="fa-solid fa-arrow-right"></i>
          </button>
          <button type="submit" class="auth-submit auth-step-submit" hidden>
            <i class="fa-solid fa-user-plus"></i> Create Account
          </button>
        </div>

      </form>
    </div>

    <div class="auth-features">
      <div class="auth-journey">
        <h3>What happens next</h3>
        <p>Creating an account is free. Here is the full path to your first event.</p>
        <ol class="auth-journey-list">
          <li class="is-current">
            <span class="auth-journey-icon"><i class="fa-solid fa-user-plus"></i></span>
            <div class="auth-journey-body">
              <strong>Create account</strong>
              <span>You are here — three quick steps on this page.</span>
            </div>
          </li>
          <li class="is-upcoming">
            <span class="auth-journey-icon"><i class="fa-solid fa-envelope-circle-check"></i></span>
            <div class="auth-journey-body">
              <strong>Verify your email</strong>
              <span>Check your inbox for a confirmation link (takes a minute).</span>
            </div>
          </li>
          <li class="is-upcoming">
            <span class="auth-journey-icon"><i class="fa-solid fa-credit-card"></i></span>
            <div class="auth-journey-body">
              <strong>Buy an event credit</strong>
              <span>Pay with MTN or Airtel when you are ready — from K450.</span>
            </div>
          </li>
          <li class="is-upcoming">
            <span class="auth-journey-icon"><i class="fa-solid fa-calendar-plus"></i></span>
            <div class="auth-journey-body">
              <strong>Create your event</strong>
              <span>Pick a template, invite guests, and track RSVPs live.</span>
            </div>
          </li>
        </ol>
        <p class="auth-journey-note">
          <i class="fa-solid fa-circle-info"></i>
          No payment required to sign up. You only pay when you want to publish an event.
        </p>
      </div>
    </div>

  </div>
</section>

@push('scripts')
<script src="{{ asset('js/register-wizard.js') }}" defer></script>
<script>
(function () {
    var toggle     = document.getElementById('auth-type-toggle');
    var typeInput  = document.getElementById('account_type');
    var nameLabel  = document.getElementById('name-label');
    var nameInput  = document.getElementById('name');
    var companyField = document.getElementById('company-field');

    if (!toggle || !typeInput) {
        return;
    }

    var config = {
        individual:   { icon: 'fa-user',     label: 'Full name',                     placeholder: 'Your full name',                      showCompany: true },
        organisation: { icon: 'fa-building', label: 'Company / Event business name', placeholder: 'Your company or event business name', showCompany: false }
    };

    function applyType(type) {
        typeInput.value = type;
        var c = config[type];

        nameLabel.innerHTML = '<i class="fa-solid ' + c.icon + '"></i> ' + c.label;
        nameInput.placeholder = c.placeholder;
        companyField.style.display = c.showCompany ? '' : 'none';

        toggle.querySelectorAll('.auth-type-btn').forEach(function (btn) {
            btn.classList.toggle('auth-type-btn--active', btn.dataset.type === type);
        });
    }

    toggle.addEventListener('click', function (e) {
        var btn = e.target.closest('.auth-type-btn');
        if (btn) applyType(btn.dataset.type);
    });

    applyType(typeInput.value || 'individual');
}());
</script>
@endpush

@endsection
