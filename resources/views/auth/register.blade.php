@extends('layouts.site')

@section('title', 'Create your account — Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
@endpush

@section('content')

{{-- ── HERO ── --}}
<section class="auth-hero">
  <div class="auth-hero-inner">

    {{-- Breadcrumbs --}}
    <nav class="auth-breadcrumb" aria-label="Breadcrumb">
      <ol>
        <li><a href="{{ url('/') }}"><i class="fa-solid fa-house"></i> Home</a></li>
        <li aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></li>
        <li><span aria-current="page">Sign Up</span></li>
      </ol>
    </nav>

    <div class="auth-hero-badge"><span class="dot"></span> Free to get started</div>

    <h1 class="auth-hero-headline">
      Create your<br>
      <span class="auth-hero-accent">Event Host</span> account
    </h1>
    <p class="auth-hero-sub">
      Join thousands of hosts creating stunning digital invitations, tracking RSVPs live, and sharing via WhatsApp — all from one platform.
    </p>

    {{-- Stats row --}}
    <div class="auth-hero-stats">
      <div class="auth-stat">
        <div class="auth-stat-n">5k+</div>
        <div class="auth-stat-l">Events hosted</div>
      </div>
      <div class="auth-stat-sep"></div>
      <div class="auth-stat">
        <div class="auth-stat-n">98%</div>
        <div class="auth-stat-l">Guest satisfaction</div>
      </div>
      <div class="auth-stat-sep"></div>
      <div class="auth-stat">
        <div class="auth-stat-n">Free</div>
        <div class="auth-stat-l">First event</div>
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

      <form method="POST" action="{{ route('register') }}">
        @csrf

        <div class="auth-fields">

          {{-- Name --}}
          <div class="auth-field">
            <label for="name" class="auth-label">
              <i class="fa-solid fa-user"></i> Full name
            </label>
            <input id="name" type="text" name="name" value="{{ old('name') }}"
                   class="auth-input {{ $errors->has('name') ? 'auth-input--error' : '' }}"
                   placeholder="Your full name" required autofocus autocomplete="name">
            @error('name')
              <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
          </div>

          {{-- Email --}}
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

          {{-- Password --}}
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

          {{-- Confirm Password --}}
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

          {{-- Optional divider --}}
          <div class="auth-optional-divider">
            <span>Optional details</span>
          </div>

          {{-- Phone --}}
          <div class="auth-field">
            <label for="phone" class="auth-label">
              <i class="fa-solid fa-phone"></i> Phone number
            </label>
            <input id="phone" type="tel" name="phone" value="{{ old('phone') }}"
                   class="auth-input {{ $errors->has('phone') ? 'auth-input--error' : '' }}"
                   placeholder="+260 97 000 0000" autocomplete="tel">
            @error('phone')
              <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
          </div>

          {{-- Company --}}
          <div class="auth-field">
            <label for="company_name" class="auth-label">
              <i class="fa-solid fa-building"></i> Company / Event business name
            </label>
            <input id="company_name" type="text" name="company_name" value="{{ old('company_name') }}"
                   class="auth-input {{ $errors->has('company_name') ? 'auth-input--error' : '' }}"
                   placeholder="Your business name (if any)" autocomplete="organization">
            @error('company_name')
              <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
          </div>

        </div>

        <button type="submit" class="auth-submit">
          <i class="fa-solid fa-user-plus"></i> Create Free Account
        </button>

        <p class="auth-terms">
          By signing up you agree to our <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>.
        </p>

      </form>
    </div>

    {{-- Side features --}}
    <div class="auth-features">
      <h3>Everything you need to host</h3>
      <ul>
        <li>
          <div class="auth-feat-icon" style="background:rgba(30,71,187,0.12)"><i class="fa-solid fa-palette"></i></div>
          <div>
            <strong>100+ Premium Templates</strong>
            <span>Weddings, birthdays, corporate &amp; more</span>
          </div>
        </li>
        <li>
          <div class="auth-feat-icon" style="background:rgba(0,206,201,0.12)"><i class="fa-solid fa-chart-line"></i></div>
          <div>
            <strong>Live RSVP Dashboard</strong>
            <span>Track responses the moment they arrive</span>
          </div>
        </li>
        <li>
          <div class="auth-feat-icon" style="background:rgba(72,199,142,0.12)"><i class="fa-brands fa-whatsapp"></i></div>
          <div>
            <strong>WhatsApp Sharing</strong>
            <span>Guests RSVP in one tap — no app needed</span>
          </div>
        </li>
        <li>
          <div class="auth-feat-icon" style="background:rgba(243,156,18,0.12)"><i class="fa-solid fa-credit-card"></i></div>
          <div>
            <strong>Local Payments</strong>
            <span>MTN MoMo, Airtel Money, Zamtel &amp; cards</span>
          </div>
        </li>
        <li>
          <div class="auth-feat-icon" style="background:rgba(30,71,187,0.12)"><i class="fa-solid fa-mobile-screen-button"></i></div>
          <div>
            <strong>Mobile Optimized</strong>
            <span>Flawless on any device, anywhere</span>
          </div>
        </li>
      </ul>

      <div class="auth-testi">
        <div class="auth-testi-stars">
          <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i>
        </div>
        <blockquote>"200 guests RSVPed with zero confusion. My mother-in-law even figured it out on WhatsApp!"</blockquote>
        <div class="auth-testi-author">
          <img src="https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w=88&h=88&q=80" alt="Namwali Musonda" width="36" height="36" loading="lazy">
          <div>
            <strong>Namwali Musonda</strong>
            <span>Wedding · Lusaka</span>
          </div>
        </div>
      </div>
    </div>

  </div>
</section>

@endsection
