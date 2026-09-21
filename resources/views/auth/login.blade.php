@extends('layouts.site', ['hideSiteHeader' => true])

@section('title', 'Sign in | Event Host')

@push('head')
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">
@endpush

@section('content')

{{-- ── HERO ── --}}
<section class="auth-hero">
  <div class="auth-hero-inner">

    <div class="auth-hero-content">
      {{-- Breadcrumbs --}}
      <nav class="auth-breadcrumb" aria-label="Breadcrumb">
        <ol>
          <li><a href="{{ url('/') }}"><i class="fa-solid fa-house"></i> Home</a></li>
          <li aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></li>
          <li><span aria-current="page">Sign In</span></li>
        </ol>
      </nav>

      <h1 class="auth-hero-headline">
        Sign in to your<br>
        <span class="auth-hero-accent">Event Host</span> account
      </h1>
      <p class="auth-hero-sub">
        Pick up right where you left off — manage your events, track RSVPs live, and keep your guests in the loop.
      </p>

      {{-- Stats row --}}
      <div class="auth-hero-stats">
        <div class="auth-stat">
          <div class="auth-stat-n">{{ number_format($eventsHosted) }}</div>
          <div class="auth-stat-l">Events hosted</div>
        </div>
        <div class="auth-stat-sep"></div>
        <div class="auth-stat">
          <div class="auth-stat-n">98%</div>
          <div class="auth-stat-l">Guest satisfaction</div>
        </div>
        <div class="auth-stat-sep"></div>
        <div class="auth-stat">
          <div class="auth-stat-n">24/7</div>
          <div class="auth-stat-l">Dashboard access</div>
        </div>
      </div>
    </div>

    <div class="auth-hero-visual auth-hero-visual--mobile-only">
      <img class="auth-hero-mockup"
           src="{{ asset('images/hero-mockup-guest.png') }}"
           width="400" height="445"
           alt="A guest smiling while RSVPing on her phone"
           loading="eager" decoding="async">
    </div>

  </div>
</section>

{{-- ── FORM SECTION ── --}}
<section class="auth-form-section">
  <div class="auth-form-wrap">

    <div class="auth-form-card">

      <div class="auth-card-header">
        <h2>Sign in to your account</h2>
        <p>Don't have an account? <a href="{{ route('register') }}">Sign up free</a></p>
      </div>

      {{-- Session Status --}}
      @if (session('status'))
        <div class="auth-status-msg">
          <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
        </div>
      @endif

      <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="auth-fields">

          {{-- Email --}}
          <div class="auth-field">
            <label for="email" class="auth-label">
              <i class="fa-solid fa-envelope"></i> Email address
            </label>
            <input id="email" type="email" name="email" value="{{ old('email') }}"
                   class="auth-input {{ $errors->has('email') ? 'auth-input--error' : '' }}"
                   placeholder="you@example.com" required autofocus autocomplete="username">
            @error('email')
              <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
          </div>

          {{-- Password --}}
          <div class="auth-field">
            <div class="auth-label-row">
              <label for="password" class="auth-label">
                <i class="fa-solid fa-lock"></i> Password
              </label>
              @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="auth-forgot">Forgot password?</a>
              @endif
            </div>
            <div class="auth-input-wrap">
              <input id="password" type="password" name="password"
                     class="auth-input {{ $errors->has('password') ? 'auth-input--error' : '' }}"
                     placeholder="Your password" required autocomplete="current-password">
              <button type="button" class="auth-eye" data-target="password" aria-label="Toggle password visibility">
                <i class="fa-solid fa-eye"></i>
              </button>
            </div>
            @error('password')
              <span class="auth-error"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</span>
            @enderror
          </div>

          {{-- Remember Me --}}
          <div class="auth-remember">
            <label class="auth-check-label">
              <input id="remember_me" type="checkbox" name="remember" class="auth-checkbox">
              <span>Remember me for 30 days</span>
            </label>
          </div>

        </div>

        <button type="submit" class="auth-submit">
          <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In
        </button>

        <p class="auth-terms">
          By signing in you agree to our <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener">Terms of Service</a> and <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">Privacy Policy</a>.
        </p>

      </form>
    </div>

    {{-- Side visual: the hero image, shown here on desktop only (mobile keeps it in the hero) --}}
    <div class="auth-features">
      <div class="auth-side-visual">
        <img class="auth-hero-mockup"
             src="{{ asset('images/hero-mockup-guest.png') }}"
             width="400" height="445"
             alt="A guest smiling while RSVPing on her phone"
             loading="lazy" decoding="async">
      </div>

      {{-- Approved host review the admin has chosen to feature. Same source as the homepage strip. --}}
      @if ($featuredReview)
      <div class="auth-testi">
        @if ($featuredReview->rating)
        <div class="auth-testi-stars" aria-label="{{ $featuredReview->rating }} out of 5 stars">
          @for ($star = 1; $star <= 5; $star++)
            <i class="fa-solid fa-star {{ $star <= $featuredReview->rating ? '' : 'is-empty' }}" aria-hidden="true"></i>
          @endfor
        </div>
        @endif
        <blockquote>&ldquo;{{ $featuredReview->body }}&rdquo;</blockquote>
        <div class="auth-testi-author">
          <img src="{{ $featuredReview->author_photo_url }}" alt="" width="36" height="36" loading="lazy">
          <div>
            <strong>{{ $featuredReview->author_name }}</strong>
            @if ($featuredReview->author_context)
              <span>{{ $featuredReview->author_context }}</span>
            @endif
          </div>
        </div>
      </div>
      @endif
    </div>

  </div>
</section>

@endsection
